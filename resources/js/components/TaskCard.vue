<script setup>
import Icon from './Icon.vue';
import { dateKey, formatDate, priorities } from '../calendar';
defineProps({ task: Object });
defineEmits(['open', 'drag']);
</script>
<template>
    <article
        class="task-card group"
        draggable="true"
        @dragstart="$emit('drag', $event, task)"
        :aria-label="task.name"
    >
        <div class="mb-3 flex items-center justify-between gap-2">
            <span class="flex min-w-0 items-center gap-1.5 text-[11px] font-medium text-stone-500">
                <span
                    class="h-1.5 w-1.5 shrink-0 rounded-full"
                    :style="{ backgroundColor: task.project.color }"
                ></span>
                <span class="truncate">{{ task.project.name }}</span>
            </span>
            <span
                class="priority"
                :class="`priority-${task.priority}`"
            >
                {{ priorities[task.priority] }}
            </span>
        </div>
        <button
            class="w-full text-left text-[13px] leading-5 font-semibold text-stone-800 focus-visible:outline-offset-4"
            @click="$emit('open', task)"
        >
            {{ task.name }}
        </button>
        <p
            v-if="task.description"
            class="mt-2 line-clamp-2 text-xs leading-5 text-stone-500"
        >
            {{ task.description }}
        </p>
        <div
            v-if="task.milestone"
            class="mt-3 flex items-center gap-1.5 text-[11px] text-emerald-800"
        >
            <Icon
                name="flag"
                :size="12"
            />
            <span class="truncate">{{ task.milestone.name }}</span>
        </div>
        <div class="mt-4 flex items-center gap-2 border-t border-stone-100 pt-3">
            <span
                class="flex items-center gap-1.5 text-[11px]"
                :class="
                    task.due_date && task.due_date < dateKey() && task.status !== 'done'
                        ? 'text-red-700'
                        : 'text-stone-500'
                "
            >
                <Icon
                    name="calendar"
                    :size="13"
                />
                {{ formatDate(task.due_date) }}
            </span>
        </div>
    </article>
</template>
