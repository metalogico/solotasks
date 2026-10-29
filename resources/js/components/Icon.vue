<script setup>
defineProps({ name: String, size: { type: Number, default: 18 } });
const paths = {
    plus: 'M12 5v14m-7-7h14',
    grid: 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
    calendar:
        'M8 2v4m8-4v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2',
    arrow: 'm9 5 7 7-7 7',
    close: 'm6 6 12 12M6 18 18 6',
    logout: 'M9 4H4v16h5m6-12 4 4-4 4m-7-4h11',
    flag: 'M5 21V3m0 1c5-4 9 4 14 0v10c-5 4-9-4-14 0',
    edit: 'm16 3 5 5-13 13H3v-5ZM14 5l5 5',
    trash: 'M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7',
    check: 'm5 12 4 4L19 6',
    expand: 'M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5',
};
</script>
<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path :d="paths[name] || paths.grid" />
    </svg>
</template>
