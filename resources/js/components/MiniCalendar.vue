<script setup>
import { computed } from 'vue';
import { monthCells, monthLabel, shiftMonth } from '../calendar';
import Icon from './Icon.vue';
const props = defineProps({ calendar: Object, month: String, loading: Boolean });
defineEmits(['month', 'open']);
const days = computed(() =>
    Object.fromEntries((props.calendar?.days || []).map((day) => [day.date, day.count])),
);

function shade(count) {
    if (count >= 5) {
        return 'heat-4';
    }
    if (count >= 3) {
        return 'heat-3';
    }
    if (count === 2) {
        return 'heat-2';
    }
    if (count === 1) {
        return 'heat-1';
    }
    return '';
}
</script>
<template>
    <section
        aria-label="Compact deadline calendar"
        class="mini-calendar"
    >
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-xs font-semibold capitalize text-stone-700">{{ monthLabel(month) }}</h2>
            <div class="flex gap-1">
                <button
                    class="icon-button h-6! w-6!"
                    aria-label="Previous month"
                    @click="$emit('month', shiftMonth(month, -1))"
                >
                    <Icon
                        name="arrow"
                        :size="13"
                        class="rotate-180"
                    />
                </button>
                <button
                    class="icon-button h-6! w-6!"
                    aria-label="Next month"
                    @click="$emit('month', shiftMonth(month, 1))"
                >
                    <Icon
                        name="arrow"
                        :size="13"
                    />
                </button>
            </div>
        </div>
        <button
            class="block w-full rounded-lg text-left"
            :aria-label="`View calendar for ${monthLabel(month)}`"
            @click="$emit('open')"
        >
            <span class="grid grid-cols-7 gap-1 text-center text-[9px] text-stone-400">
                <span v-for="day in ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']">{{ day }}</span>
            </span>
            <span class="mt-1.5 grid grid-cols-7 gap-1">
                <span
                    v-for="cell in monthCells(month)"
                    :key="cell.key"
                    class="mini-day"
                    :class="[
                        cell.inMonth ? shade(days[cell.key] || 0) : 'opacity-25',
                        { 'ring-1 ring-emerald-800 ring-offset-1': cell.today },
                    ]"
                    :title="`${cell.key}: ${days[cell.key] || 0} task${days[cell.key] === 1 ? '' : 's'} due`"
                >
                    {{ cell.day }}
                </span>
            </span>
            <span class="mt-3 flex items-center justify-between text-[10px] text-stone-500">
                <span>
                    {{
                        loading
                            ? 'Updating…'
                            : `${calendar?.total || 0} task${calendar?.total === 1 ? '' : 's'} due this month`
                    }}
                </span>
                <Icon
                    name="expand"
                    :size="12"
                />
            </span>
        </button>
        <div class="mt-2 flex items-center gap-1 text-[9px] text-stone-400">
            <span class="mr-1">Less</span>
            <i
                v-for="level in [0, 1, 2, 3, 4]"
                class="h-2 w-2 rounded-xs bg-stone-100"
                :class="`heat-${level}`"
            ></i>
            <span class="ml-1">More</span>
        </div>
    </section>
</template>
