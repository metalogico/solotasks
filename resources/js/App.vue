<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import Icon from './components/Icon.vue';
import MiniCalendar from './components/MiniCalendar.vue';
import TaskCard from './components/TaskCard.vue';
import {
    currentMonth,
    formatDate,
    monthCells,
    monthLabel,
    priorities,
    shiftMonth,
    statuses,
} from './calendar';

const logoUrl = '/logo.svg';
const ready = ref(false);
const authenticated = ref(false);
const configured = ref(true);
const username = ref('');
const csrf = ref(document.querySelector('meta[name="csrf-token"]').content);
const login = reactive({ username: '', password: '' });
const error = ref('');
const formError = ref('');
const saving = ref(false);
const loading = ref(false);
const projectId = ref('');
const month = ref(currentMonth());
const includeDone = ref(false);
const view = ref('board');
const calendarProjectId = ref('');
const calendarMonth = ref(currentMonth());
const board = ref({ tasks: [], projects: [], milestones: [], calendar: { days: [], total: 0 } });
const extendedCalendar = ref({ days: [], total: 0 });
const calendarLoading = ref(false);
const dragStatus = ref('');
const dialog = ref(null);
const modalType = ref('task');
const editingId = ref(null);
const form = reactive({});
let latestBoardRequest = 0;
let latestCalendarRequest = 0;

const resourcesByType = {
    task: 'tasks',
    project: 'projects',
    milestone: 'milestones',
};

const deleteMessages = {
    task: 'Permanently delete this task?',
    project: 'Delete this empty project?',
    milestone: 'Delete this milestone? Its tasks and their due dates will be kept.',
};

const projectColors = [
    { name: 'Purple', value: '#7551ff' },
    { name: 'Blue', value: '#2d86f5' },
    { name: 'Pink', value: '#ed5991' },
    { name: 'Orange', value: '#f78a36' },
    { name: 'Yellow', value: '#efb82a' },
    { name: 'Green', value: '#2eb884' },
    { name: 'Gray', value: '#95a1b8' },
];

const selectedProject = computed(() =>
    board.value.projects.find((project) => project.id === Number(projectId.value)),
);
const title = computed(() => selectedProject.value?.name || 'All tasks');
const activeTasks = computed(() => board.value.tasks.filter((task) => task.status !== 'done'));
const undated = computed(() => activeTasks.value.filter((task) => !task.due_date).length);
const tasksByStatus = computed(() =>
    Object.fromEntries(
        statuses.map((status) => [
            status.key,
            board.value.tasks.filter((task) => task.status === status.key),
        ]),
    ),
);
const visibleMilestones = computed(() =>
    board.value.milestones.filter(
        (milestone) => !projectId.value || milestone.project_id === Number(projectId.value),
    ),
);
const formMilestones = computed(() =>
    board.value.milestones.filter((milestone) => milestone.project_id === Number(form.project_id)),
);
const extendedDays = computed(() =>
    Object.fromEntries(extendedCalendar.value.days.map((day) => [day.date, day])),
);

async function api(path, method = 'GET', data) {
    const response = await fetch(`/api/v1/${path}`, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf.value,
        },
        credentials: 'same-origin',
        ...(data === undefined ? {} : { body: JSON.stringify(data) }),
    });
    const result = response.status === 204 ? {} : await response.json();

    if (!response.ok) {
        if (response.status === 401) {
            authenticated.value = false;
            dialog.value?.close();
        }

        if (response.status === 419) {
            const sessionResponse = await fetch('/api/v1/session', {
                headers: { Accept: 'application/json' },
            });
            const session = await sessionResponse.json();
            csrf.value = session.csrf_token;
            authenticated.value = session.authenticated;
            throw new Error('Your session has been refreshed. Please try saving again.');
        }

        const message = result.errors
            ? Object.values(result.errors).flat().join(' ')
            : result.message || 'Something went wrong. Please try again.';
        throw new Error(message);
    }

    if (result.csrf_token) {
        csrf.value = result.csrf_token;
    }

    return result;
}

function boardQuery(selectedProjectId, selectedMonth) {
    return new URLSearchParams({
        ...(selectedProjectId ? { project_id: selectedProjectId } : {}),
        month: selectedMonth,
        include_done: includeDone.value ? '1' : '0',
    });
}

async function loadBoard() {
    const requestId = ++latestBoardRequest;
    loading.value = true;

    try {
        const result = await api(`board?${boardQuery(projectId.value, month.value)}`);
        // A slower response must not replace a newer project or month selection.
        if (requestId === latestBoardRequest) {
            board.value = result;
        }
    } catch (cause) {
        if (requestId === latestBoardRequest) {
            error.value = cause.message;
        }
    } finally {
        if (requestId === latestBoardRequest) {
            loading.value = false;
        }
    }
}

async function loadCalendar() {
    const requestId = ++latestCalendarRequest;
    calendarLoading.value = true;

    try {
        const result = await api(
            `board?${boardQuery(calendarProjectId.value, calendarMonth.value)}`,
        );
        if (requestId === latestCalendarRequest) {
            extendedCalendar.value = result.calendar;
        }
    } catch (cause) {
        if (requestId === latestCalendarRequest) {
            error.value = cause.message;
        }
    } finally {
        if (requestId === latestCalendarRequest) {
            calendarLoading.value = false;
        }
    }
}

async function refresh() {
    await loadBoard();
    if (view.value === 'calendar') {
        await loadCalendar();
    }
}

async function signIn() {
    saving.value = true;
    error.value = '';

    try {
        const result = await api('session', 'POST', login);
        authenticated.value = result.authenticated;
        username.value = result.username;
        login.password = '';
        await loadBoard();
        includeDone.value = board.value.defaults.calendar_include_done;
    } catch (cause) {
        error.value = cause.message;
    } finally {
        saving.value = false;
    }
}

async function signOut() {
    try {
        await api('session', 'DELETE');
        authenticated.value = false;
        board.value = { tasks: [], projects: [], milestones: [], calendar: { days: [], total: 0 } };
    } catch (cause) {
        error.value = cause.message;
    }
}

function chooseProject(id) {
    projectId.value = id;
    view.value = 'board';
}

function openCalendar() {
    calendarMonth.value = month.value;
    calendarProjectId.value = '';
    view.value = 'calendar';
    loadCalendar();
}

function initialForm(type) {
    const defaultProjectId = projectId.value || board.value.projects[0]?.id || '';

    if (type === 'project') {
        return { name: '', color: '#2eb884' };
    }

    if (type === 'milestone') {
        return { name: '', due_date: '', project_id: defaultProjectId };
    }

    return {
        name: '',
        description: '',
        priority: 'medium',
        status: 'backlog',
        due_date: '',
        project_id: defaultProjectId,
        milestone_id: '',
    };
}

async function openModal(type, item = null, initial = {}) {
    modalType.value = type;
    editingId.value = item?.id || null;
    formError.value = '';

    for (const key of Object.keys(form)) {
        delete form[key];
    }
    Object.assign(form, initialForm(type));

    if (item) {
        for (const key of Object.keys(form)) {
            form[key] = item[key] ?? '';
        }
    }
    Object.assign(form, initial);

    await nextTick();
    dialog.value.showModal();
}

function manageMilestone(event) {
    const id = event.target.value;
    if (id) {
        const milestone = board.value.milestones.find((item) => item.id === Number(id));
        openModal('milestone', milestone || null);
    }
    event.target.value = '';
}

function closeOnBackdrop(event) {
    if (event.target === dialog.value) {
        dialog.value.close();
    }
}

async function saveForm() {
    saving.value = true;
    formError.value = '';

    try {
        const payload = { ...form };
        if (modalType.value !== 'project') {
            payload.project_id = Number(payload.project_id) || null;
        }
        if (modalType.value === 'task') {
            payload.milestone_id = Number(payload.milestone_id) || null;
            payload.due_date ||= null;
        }

        const resource = resourcesByType[modalType.value];
        const path = editingId.value ? `${resource}/${editingId.value}` : resource;
        const method = editingId.value ? 'PATCH' : 'POST';
        const result = await api(path, method, payload);

        if (modalType.value === 'project' && !editingId.value) {
            projectId.value = result.data.id;
        }
        dialog.value.close();
        await refresh();
    } catch (cause) {
        formError.value = cause.message;
    } finally {
        saving.value = false;
    }
}

async function deleteItem() {
    if (!confirm(deleteMessages[modalType.value])) {
        return;
    }
    saving.value = true;

    try {
        const resource = resourcesByType[modalType.value];
        await api(`${resource}/${editingId.value}`, 'DELETE');

        if (modalType.value === 'project') {
            if (Number(projectId.value) === editingId.value) {
                projectId.value = '';
            }
            if (Number(calendarProjectId.value) === editingId.value) {
                calendarProjectId.value = '';
            }
        }
        dialog.value.close();
        await refresh();
    } catch (cause) {
        formError.value = cause.message;
    } finally {
        saving.value = false;
    }
}

async function moveTask(task, status) {
    error.value = '';

    try {
        await api(`tasks/${task.id}`, 'PATCH', { status });
        await refresh();
    } catch (cause) {
        error.value = cause.message;
        await refresh();
    }
}

function startDrag(event, task) {
    event.dataTransfer.setData('text/plain', String(task.id));
    event.dataTransfer.effectAllowed = 'move';
}

async function dropTask(event, status) {
    dragStatus.value = '';
    const taskId = Number(event.dataTransfer.getData('text/plain'));
    const task = board.value.tasks.find((task) => task.id === taskId);

    if (task && task.status !== status) {
        await moveTask(task, status);
    }
}

watch([projectId, month, includeDone], () => {
    if (authenticated.value) {
        loadBoard();
    }
});

watch([calendarProjectId, calendarMonth, includeDone], () => {
    if (authenticated.value && view.value === 'calendar') {
        loadCalendar();
    }
});

onMounted(async () => {
    try {
        const session = await api('session');
        authenticated.value = session.authenticated;
        configured.value = session.configured;
        username.value = session.username;

        if (authenticated.value) {
            await loadBoard();
            includeDone.value = board.value.defaults.calendar_include_done;
        }
    } catch {
        error.value = 'Unable to connect. Reload the page to try again.';
    } finally {
        ready.value = true;
    }
});
</script>

<template>
    <div
        v-if="!ready"
        class="grid min-h-dvh place-items-center text-sm text-stone-500"
    >
        Opening your workspace…
    </div>
    <main
        v-else-if="!authenticated"
        class="grid min-h-dvh place-items-center p-6"
    >
        <div class="w-full max-w-96">
            <div class="mb-10 flex items-center gap-3">
                <img
                    :src="logoUrl"
                    alt=""
                    aria-hidden="true"
                    class="h-11 w-16 shrink-0 object-contain"
                />
                <span class="text-xl font-semibold tracking-tight">SoloTasks</span>
            </div>
            <h1 class="text-3xl font-semibold tracking-tight">Sign in</h1>
            <form
                @submit.prevent="signIn"
                class="mt-8 grid gap-4"
            >
                <label>
                    <span class="label">Username</span>
                    <input
                        class="field"
                        v-model="login.username"
                        required
                        autocomplete="username"
                        autofocus
                    />
                </label>
                <label>
                    <span class="label">Password</span>
                    <input
                        class="field"
                        v-model="login.password"
                        required
                        type="password"
                        autocomplete="current-password"
                    />
                </label>
                <p
                    v-if="!configured"
                    class="text-xs leading-5 text-amber-800"
                >
                    Personal access must be configured in the .env file. Follow the README
                    instructions.
                </p>
                <p
                    v-if="error"
                    role="alert"
                    class="text-xs leading-5 text-red-700"
                >
                    {{ error }}
                </p>
                <button
                    class="btn btn-primary mt-2"
                    :disabled="saving || !configured"
                >
                    {{ saving ? 'Signing in…' : 'Sign in' }}
                    <Icon
                        name="arrow"
                        :size="15"
                    />
                </button>
            </form>
            <p class="mt-8 text-[11px] text-stone-400">Private access · No sign-up</p>
        </div>
    </main>
    <div
        v-else
        class="
            app-layout grid h-dvh grid-cols-[252px_minmax(0,1fr)] overflow-hidden max-md:h-auto
            max-md:min-h-dvh max-md:grid-cols-1 max-md:overflow-visible
        "
    >
        <aside
            class="
                sidebar flex min-h-0 flex-col border-r border-stone-200/80 bg-[#fbfcf9] px-5
                py-4 max-md:sticky max-md:top-0 max-md:z-20 max-md:grid
                max-md:grid-cols-[minmax(140px,1fr)_minmax(0,180px)] max-md:gap-x-4
                max-md:border-r-0 max-md:border-b max-md:px-4 max-md:py-3
            "
        >
            <div class="mb-5 flex items-center gap-2.5 max-md:mb-3">
                <img
                    :src="logoUrl"
                    alt=""
                    aria-hidden="true"
                    class="h-8 w-10 shrink-0 object-contain"
                />
                <span class="text-lg font-semibold tracking-tight">SoloTasks</span>
            </div>
            <div class="max-md:col-start-1 max-md:row-start-2">
                <button
                    class="nav-item"
                    :class="{ 'nav-active': !projectId && view === 'board' }"
                    @click="chooseProject('')"
                >
                    <Icon
                        name="grid"
                        :size="16"
                    />
                    All tasks
                    <span class="ml-auto text-[10px] opacity-60">
                        {{ board.projects.reduce((total, p) => total + p.tasks_count, 0) }}
                    </span>
                </button>
                <button
                    class="nav-item mt-1 max-md:hidden"
                    :class="{ 'nav-active': view === 'calendar' }"
                    @click="openCalendar"
                >
                    <Icon
                        name="calendar"
                        :size="16"
                    />
                    Calendar
                </button>
            </div>
            <div
                class="mt-5 flex min-h-0 flex-1 flex-col max-md:col-start-1 max-md:row-start-3 max-md:mt-0"
            >
                <div class="mb-3 flex items-center justify-between max-md:hidden">
                    <h2
                        class="text-[10px] font-semibold tracking-[0.14em] text-stone-400 uppercase"
                    >
                        Your projects
                    </h2>
                    <button
                        class="icon-button h-5! w-5!"
                        aria-label="New project"
                        @click="openModal('project')"
                    >
                        <Icon
                            name="plus"
                            :size="14"
                        />
                    </button>
                </div>
                <div class="min-h-0 overflow-y-auto max-md:hidden">
                    <div
                        v-for="project in board.projects"
                        :key="project.id"
                        class="group flex items-center rounded-lg"
                        :class="{
                            'bg-[#e7ede3]': project.id === Number(projectId) && view === 'board',
                        }"
                    >
                        <button
                            class="nav-item min-w-0 flex-1"
                            :class="{
                                'text-forest! font-semibold':
                                    project.id === Number(projectId) && view === 'board',
                            }"
                            @click="chooseProject(project.id)"
                        >
                            <span
                                class="h-2 w-2 shrink-0 rounded-xs"
                                :style="{ backgroundColor: project.color }"
                            ></span>
                            <span class="truncate">{{ project.name }}</span>
                            <span class="ml-auto text-[10px] opacity-60">
                                {{ project.tasks_count }}
                            </span>
                        </button>
                        <button
                            class="icon-button mr-1 h-6! w-6! opacity-0 group-focus-within:opacity-100 group-hover:opacity-100"
                            :aria-label="`Edit project ${project.name}`"
                            @click="openModal('project', project)"
                        >
                            <Icon
                                name="edit"
                                :size="12"
                            />
                        </button>
                    </div>
                    <p
                        v-if="!board.projects.length"
                        class="px-3 text-xs leading-5 text-stone-400"
                    >
                        Add your first project.
                    </p>
                    <div class="mt-7 mb-2 flex items-center justify-between">
                        <h2
                            class="text-[10px] font-semibold tracking-[0.14em] text-stone-400 uppercase"
                        >
                            Milestones
                        </h2>
                        <button
                            class="icon-button h-5! w-5!"
                            aria-label="New milestone"
                            :disabled="!board.projects.length"
                            @click="openModal('milestone')"
                        >
                            <Icon
                                name="plus"
                                :size="14"
                            />
                        </button>
                    </div>
                    <button
                        v-for="milestone in visibleMilestones"
                        :key="milestone.id"
                        class="nav-item items-start!"
                        @click="openModal('milestone', milestone)"
                    >
                        <Icon
                            name="flag"
                            :size="14"
                            class="mt-0.5 shrink-0"
                        />
                        <span class="min-w-0">
                            <span class="block truncate">{{ milestone.name }}</span>
                            <span class="mt-1 block text-[10px] text-stone-400">
                                {{ formatDate(milestone.due_date) }}
                            </span>
                        </span>
                    </button>
                    <p
                        v-if="!visibleMilestones.length"
                        class="px-3 text-[11px] leading-5 text-stone-400"
                    >
                        Small goals for your projects.
                    </p>
                </div>
                <div class="hidden gap-1 max-md:flex">
                    <select
                        class="select-field field min-w-0 text-xs!"
                        :value="projectId"
                        aria-label="Filter board by project"
                        @change="chooseProject($event.target.value)"
                    >
                        <option value="">All projects</option>
                        <option
                            v-for="project in board.projects"
                            :value="project.id"
                        >
                            {{ project.name }}
                        </option>
                    </select>
                    <button
                        class="icon-button"
                        aria-label="New project"
                        @click="openModal('project')"
                    >
                        <Icon name="plus" />
                    </button>
                    <button
                        v-if="selectedProject"
                        class="icon-button"
                        aria-label="Edit selected project"
                        @click="openModal('project', selectedProject)"
                    >
                        <Icon name="edit" />
                    </button>
                </div>
            </div>
            <div
                class="
                    mt-3 shrink-0 border-t border-stone-200/80 pt-3 max-md:col-start-2
                    max-md:row-span-4 max-md:row-start-1 max-md:mt-0 max-md:border-t-0
                    max-md:pt-0
                "
            >
                <MiniCalendar
                    :month="month"
                    :calendar="board.calendar"
                    :loading="loading"
                    @month="month = $event"
                    @open="openCalendar"
                />
                <p class="mt-2 text-[9px] text-stone-400">
                    {{ selectedProject?.name || 'All projects' }} ·
                    {{ includeDone ? 'Done included' : 'Done excluded' }}
                </p>
            </div>
            <div
                class="
                    mt-3 flex shrink-0 items-center justify-between border-t
                    border-stone-200/80 pt-3 max-md:col-start-1 max-md:row-start-4
                    max-md:mt-1 max-md:border-t-0 max-md:pt-0
                "
            >
                <span class="flex items-center gap-2 text-[11px] text-stone-500">
                    <span
                        class="grid h-6 w-6 place-items-center rounded-full bg-[#e7ede3] text-[10px] font-semibold text-forest"
                    >
                        {{ username?.slice(0, 1).toUpperCase() }}
                    </span>
                    {{ username }}
                </span>
                <button
                    class="icon-button"
                    aria-label="Sign out"
                    @click="signOut"
                >
                    <Icon
                        name="logout"
                        :size="15"
                    />
                </button>
            </div>
        </aside>
        <main class="flex min-h-0 min-w-0 flex-col max-md:min-h-[65dvh]">
            <header
                class="
                    flex h-[76px] shrink-0 items-center justify-between border-b
                    border-stone-200/70 bg-white/60 px-8 max-md:h-14 max-md:px-4
                "
            >
                <div class="flex items-center gap-2 text-xs text-stone-400">
                    <span>My workspace</span>
                    <span>/</span>
                    <span class="text-stone-600">
                        {{ view === 'calendar' ? 'Calendar' : title }}
                    </span>
                </div>
                <span class="text-[11px] text-stone-400 max-sm:hidden">
                    {{
                        new Intl.DateTimeFormat('en-GB', {
                            weekday: 'long',
                            day: 'numeric',
                            month: 'long',
                        }).format(new Date())
                    }}
                </span>
            </header>
            <div
                v-if="error"
                role="alert"
                class="flex items-center justify-between bg-red-50 px-8 py-3 text-xs text-red-800"
            >
                {{ error }}
                <button
                    aria-label="Dismiss message"
                    @click="error = ''"
                >
                    <Icon
                        name="close"
                        :size="14"
                    />
                </button>
            </div>
            <section class="shrink-0 px-8 pt-8 pb-6 max-md:px-4 max-md:pt-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="text-[28px] font-semibold tracking-[-0.035em]">
                            {{ view === 'board' ? title : 'Your month at a glance' }}
                        </h1>
                        <p class="mt-2 text-xs text-stone-500">
                            {{
                                view === 'board'
                                    ? `${activeTasks.length} open task${activeTasks.length === 1 ? '' : 's'} · ${undated} without a due date`
                                    : 'See how deadlines are spread out and plan ahead.'
                            }}
                        </p>
                    </div>
                    <button
                        class="btn btn-primary"
                        :disabled="!board.projects.length"
                        @click="
                            openModal(
                                'task',
                                null,
                                view === 'calendar'
                                    ? { project_id: calendarProjectId || board.projects[0]?.id }
                                    : {},
                            )
                        "
                    >
                        <Icon
                            name="plus"
                            :size="16"
                        />
                        New task
                    </button>
                </div>
                <div class="mt-7 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-1 rounded-lg bg-stone-200/50 p-1">
                        <button
                            class="flex items-center gap-2 rounded-md px-3 py-1.5 text-xs"
                            :class="
                                view === 'board'
                                    ? 'bg-white shadow-xs text-stone-800'
                                    : 'text-stone-500'
                            "
                            @click="view = 'board'"
                        >
                            <Icon
                                name="grid"
                                :size="14"
                            />
                            Board
                        </button>
                        <button
                            class="flex items-center gap-2 rounded-md px-3 py-1.5 text-xs"
                            :class="
                                view === 'calendar'
                                    ? 'bg-white shadow-xs text-stone-800'
                                    : 'text-stone-500'
                            "
                            @click="openCalendar"
                        >
                            <Icon
                                name="calendar"
                                :size="14"
                            />
                            Full month
                        </button>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <select
                            class="select-field max-w-40 rounded-md border-0 bg-transparent py-1 text-xs text-stone-500"
                            aria-label="Manage milestones"
                            :disabled="!board.projects.length"
                            @change="manageMilestone"
                        >
                            <option value="">Milestones…</option>
                            <option value="new">+ New milestone</option>
                            <option
                                v-for="item in visibleMilestones"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option>
                        </select>
                        <label class="flex items-center gap-2 text-[11px] text-stone-500">
                            <input
                                type="checkbox"
                                v-model="includeDone"
                                class="accent-emerald-800"
                            />
                            Include completed tasks
                        </label>
                        <span
                            v-if="loading || calendarLoading"
                            role="status"
                            class="text-[10px] text-stone-400"
                        >
                            Updating…
                        </span>
                    </div>
                </div>
            </section>
            <div
                v-if="!board.projects.length && !loading"
                class="mx-8 mb-8 rounded-xl border border-dashed border-stone-300 bg-white p-8 text-center"
            >
                <h2 class="font-semibold">Where would you like to start?</h2>
                <p class="mt-2 text-sm text-stone-500">
                    Create a project and add your first tasks.
                </p>
                <button
                    class="btn btn-primary mt-5"
                    @click="openModal('project')"
                >
                    <Icon
                        name="plus"
                        :size="15"
                    />
                    Create your first project
                </button>
            </div>
            <div
                v-else-if="view === 'board'"
                class="min-h-0 flex-1 overflow-auto px-8 pb-8 max-md:px-4"
                aria-label="Task board"
            >
                <div class="grid min-h-full min-w-[960px] grid-cols-5 items-start gap-4">
                    <section
                        v-for="status in statuses"
                        :key="status.key"
                        class="min-h-48 rounded-xl bg-[#edf0e9]/75 p-2.5 transition"
                        :class="{
                            'ring-2 ring-inset ring-emerald-700 bg-emerald-50':
                                dragStatus === status.key,
                        }"
                        :aria-label="status.name"
                        @dragover.prevent="dragStatus = status.key"
                        @dragleave.self="dragStatus = ''"
                        @drop.prevent="dropTask($event, status.key)"
                    >
                        <div class="flex items-center gap-2 px-1.5 pt-1 pb-4">
                            <span
                                class="h-2 w-2 rounded-full"
                                :style="{ backgroundColor: status.color }"
                            ></span>
                            <h2 class="text-xs font-semibold text-stone-700">{{ status.name }}</h2>
                            <span
                                class="rounded-md bg-white/70 px-1.5 py-0.5 text-[10px] text-stone-400"
                            >
                                {{ tasksByStatus[status.key].length }}
                            </span>
                            <button
                                class="icon-button ml-auto h-5! w-5!"
                                :aria-label="`Add task to ${status.name}`"
                                @click="openModal('task', null, { status: status.key })"
                            >
                                <Icon
                                    name="plus"
                                    :size="14"
                                />
                            </button>
                        </div>
                        <div class="grid gap-2.5">
                            <TaskCard
                                v-for="task in tasksByStatus[status.key]"
                                :key="task.id"
                                :task="task"
                                @open="openModal('task', $event)"
                                @drag="startDrag"
                            />
                        </div>
                        <button
                            class="
                                mt-3 flex w-full items-center gap-2 rounded-lg
                                px-2 py-2 text-[11px] text-stone-400
                                hover:bg-white/60 hover:text-stone-600
                            "
                            @click="openModal('task', null, { status: status.key })"
                        >
                            <Icon
                                name="plus"
                                :size="13"
                            />
                            Add a task
                        </button>
                    </section>
                </div>
            </div>
            <section
                v-else
                class="min-h-0 flex-1 overflow-auto px-8 pb-8 max-md:px-4"
                aria-label="Full monthly calendar"
            >
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <button
                            class="icon-button"
                            aria-label="Previous month in full calendar"
                            @click="calendarMonth = shiftMonth(calendarMonth, -1)"
                        >
                            <Icon
                                name="arrow"
                                class="rotate-180"
                            />
                        </button>
                        <h2 class="text-lg font-semibold capitalize">
                            {{ monthLabel(calendarMonth) }}
                        </h2>
                        <button
                            class="icon-button"
                            aria-label="Next month in full calendar"
                            @click="calendarMonth = shiftMonth(calendarMonth, 1)"
                        >
                            <Icon name="arrow" />
                        </button>
                        <button
                            class="btn min-h-8! py-1!"
                            @click="calendarMonth = currentMonth()"
                        >
                            Today
                        </button>
                        <input
                            type="month"
                            v-model="calendarMonth"
                            required
                            aria-label="Jump to month"
                            class="field w-40! text-xs!"
                            @change="calendarMonth ||= currentMonth()"
                        />
                    </div>
                    <select
                        class="select-field field w-auto! min-w-48 text-xs!"
                        v-model="calendarProjectId"
                        aria-label="Filter full calendar by project"
                    >
                        <option value="">All projects</option>
                        <option
                            v-for="project in board.projects"
                            :key="project.id"
                            :value="project.id"
                        >
                            {{ project.name }}
                        </option>
                    </select>
                </div>
                <p class="mb-4 text-xs text-stone-500">
                    {{ extendedCalendar.total }} task{{
                        extendedCalendar.total === 1 ? '' : 's'
                    }}
                    due · {{ includeDone ? 'Done included' : 'Done excluded' }} · This filter is
                    independent of the board.
                </p>
                <div
                    class="min-w-[750px] overflow-hidden rounded-xl border border-stone-200 bg-white"
                >
                    <div class="grid grid-cols-7 border-b border-stone-200 bg-stone-50">
                        <span
                            v-for="day in [
                                'Monday',
                                'Tuesday',
                                'Wednesday',
                                'Thursday',
                                'Friday',
                                'Saturday',
                                'Sunday',
                            ]"
                            class="px-3 py-3 text-[10px] font-medium text-stone-500"
                        >
                            {{ day }}
                        </span>
                    </div>
                    <div class="grid grid-cols-7">
                        <div
                            v-for="cell in monthCells(calendarMonth)"
                            :key="cell.key"
                            class="min-h-32 border-r border-b border-stone-100 p-2"
                            :class="{ 'bg-stone-50 text-stone-400': !cell.inMonth }"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <button
                                    class="grid h-6 w-6 place-items-center rounded-full text-[11px]"
                                    :class="
                                        cell.today
                                            ? 'bg-forest text-white font-semibold'
                                            : 'hover:bg-stone-100'
                                    "
                                    :aria-label="`New task due on ${cell.key}`"
                                    @click="
                                        openModal('task', null, {
                                            due_date: cell.key,
                                            project_id: calendarProjectId || board.projects[0]?.id,
                                        })
                                    "
                                >
                                    {{ cell.day }}
                                </button>
                                <span
                                    v-if="extendedDays[cell.key]"
                                    class="text-[10px] text-stone-400"
                                >
                                    {{ extendedDays[cell.key].count }}
                                </span>
                            </div>
                            <button
                                v-for="task in extendedDays[cell.key]?.tasks || []"
                                :key="task.id"
                                @click="openModal('task', task)"
                                class="
                                    mb-1.5 block w-full rounded-md
                                    border-l-2 bg-[#f1f5ef] px-2 py-1.5
                                    text-left text-[10px] leading-4
                                    hover:bg-[#e5eddf]
                                "
                                :style="{ borderColor: task.project.color }"
                            >
                                <span class="block truncate text-[9px] text-stone-500">
                                    {{ task.project.name }}
                                </span>
                                <!-- Keep the inline labels adjacent to preserve calendar wrapping. -->
                                <span
                                    :class="{ 'line-through opacity-60': task.status === 'done' }"
                                >{{ task.name }}</span><span
                                    v-if="task.priority === 'high'"
                                    class="ml-1 text-red-700"
                                >•</span>
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <dialog
        ref="dialog"
        class="w-[540px] max-w-[calc(100%-2rem)] rounded-2xl border border-stone-200 bg-[#fbfcf9] p-0 shadow-2xl"
        @close="formError = ''"
        @click="closeOnBackdrop"
    >
        <form @submit.prevent="saveForm">
            <div class="flex items-center justify-between border-b border-stone-200 px-6 py-5">
                <div>
                    <p class="mb-1 text-[10px] tracking-widest text-stone-400 uppercase">
                        Your personal workspace
                    </p>
                    <h2 class="text-lg font-semibold">
                        {{ editingId ? 'Edit' : 'New' }} {{ modalType }}
                    </h2>
                </div>
                <button
                    type="button"
                    class="icon-button"
                    aria-label="Close dialog"
                    @click="dialog.close()"
                >
                    <Icon name="close" />
                </button>
            </div>
            <div class="grid gap-4 p-6">
                <label>
                    <span class="label">
                        Name
                        <span class="text-emerald-700">*</span>
                    </span>
                    <input
                        class="field"
                        v-model="form.name"
                        required
                        :maxlength="
                            modalType === 'task' ? 200 : modalType === 'project' ? 100 : 150
                        "
                        autofocus
                    />
                </label>
                <template v-if="modalType !== 'project'">
                    <label>
                        <span class="label">
                            Project
                            <span class="text-emerald-700">*</span>
                        </span>
                        <select
                            class="select-field field"
                            v-model="form.project_id"
                            required
                            :disabled="modalType === 'milestone' && !!editingId"
                            @change="form.milestone_id = ''"
                        >
                            <option
                                value=""
                                disabled
                            >
                                Select a project
                            </option>
                            <option
                                v-for="project in board.projects"
                                :key="project.id"
                                :value="project.id"
                            >
                                {{ project.name }}
                            </option>
                        </select>
                    </label>
                    <label v-if="modalType === 'task'">
                        <span class="label">
                            Milestone
                            <span class="font-normal text-stone-400">· optional</span>
                        </span>
                        <select
                            class="select-field field"
                            v-model="form.milestone_id"
                        >
                            <option value="">No milestone</option>
                            <option
                                v-for="item in formMilestones"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }} · {{ formatDate(item.due_date) }}
                            </option>
                        </select>
                    </label>
                    <label v-if="modalType === 'task'">
                        <span class="label">Description</span>
                        <textarea
                            class="field min-h-24 resize-y"
                            v-model="form.description"
                            maxlength="20000"
                            placeholder="What needs to be done?"
                        ></textarea>
                    </label>
                    <div class="grid grid-cols-2 gap-4">
                        <label>
                            <span class="label">
                                Due date {{ modalType === 'milestone' ? '*' : '' }}
                            </span>
                            <input
                                class="field"
                                type="date"
                                v-model="form.due_date"
                                :required="modalType === 'milestone'"
                            />
                        </label>
                        <label v-if="modalType === 'task'">
                            <span class="label">Priority</span>
                            <select
                                class="select-field field"
                                v-model="form.priority"
                            >
                                <option
                                    v-for="(name, key) in priorities"
                                    :key="key"
                                    :value="key"
                                >
                                    {{ name }}
                                </option>
                            </select>
                        </label>
                    </div>
                    <template v-if="modalType === 'task'">
                        <label>
                            <span class="label">Column</span>
                            <select
                                class="select-field field"
                                v-model="form.status"
                            >
                                <option
                                    v-for="status in statuses"
                                    :key="status.key"
                                    :value="status.key"
                                >
                                    {{ status.name }}
                                </option>
                            </select>
                        </label>
                        <p class="text-[11px] leading-5 text-stone-400">
                            The due date places the task in the calendar. Leave it blank to schedule
                            it later.
                        </p>
                    </template>
                    <p
                        v-else
                        class="text-[11px] leading-5 text-stone-400"
                    >
                        Milestone and task due dates are independent.
                    </p>
                </template>
                <fieldset v-else>
                    <legend class="label">Project color</legend>
                    <div class="flex flex-wrap items-center gap-3 px-1 py-2">
                        <label
                            v-for="color in projectColors"
                            :key="color.value"
                            :title="color.name"
                            class="relative flex h-10 w-10 cursor-pointer items-center justify-center rounded-full"
                        >
                            <input
                                type="radio"
                                name="project-color"
                                v-model="form.color"
                                :value="color.value"
                                :aria-label="color.name"
                                required
                                class="peer sr-only"
                            />
                            <span
                                class="
                                    absolute inset-0 rounded-full
                                    ring-offset-[#fbfcf9]
                                    peer-checked:ring-2
                                    peer-checked:ring-current
                                    peer-checked:ring-offset-3
                                    peer-focus-visible:outline-2
                                    peer-focus-visible:outline-offset-4
                                "
                                :style="{ backgroundColor: color.value, color: color.value }"
                            ></span>
                            <Icon
                                name="check"
                                :size="20"
                                class="pointer-events-none relative text-white opacity-0 peer-checked:opacity-100"
                            />
                        </label>
                    </div>
                </fieldset>
                <p
                    v-if="formError"
                    role="alert"
                    class="rounded-lg bg-red-50 px-3 py-2 text-xs leading-5 text-red-800"
                >
                    {{ formError }}
                </p>
            </div>
            <div class="flex items-center justify-between border-t border-stone-200 px-6 py-4">
                <button
                    v-if="editingId"
                    type="button"
                    class="icon-button text-red-700!"
                    :disabled="saving"
                    aria-label="Delete"
                    @click="deleteItem"
                >
                    <Icon
                        name="trash"
                        :size="17"
                    />
                </button>
                <span v-else></span>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="btn"
                        :disabled="saving"
                        @click="dialog.close()"
                    >
                        Cancel
                    </button>
                    <button
                        class="btn btn-primary"
                        :disabled="saving"
                    >
                        {{ saving ? 'Saving…' : 'Save' }}
                    </button>
                </div>
            </div>
        </form>
    </dialog>
</template>
