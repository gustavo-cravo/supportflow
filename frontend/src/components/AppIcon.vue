<script setup>
defineProps({
    name: { type: String, default: 'tickets' },
    size: { type: [Number, String], default: 20 },
})
const paths = {
    dashboard: ['M3 3h7v7H3zM14 3h7v11h-7zM3 14h7v7H3zM14 18h7v3h-7z'],
    tickets: ['M4 4h16v5a3 3 0 0 0 0 6v5H4v-5a3 3 0 0 0 0-6z', 'M14 7v2m0 3v2m0 3v1'],
    tasks: ['M9 5h11M9 12h11M9 19h11M3 5l1 1 2-2M3 12l1 1 2-2M3 19l1 1 2-2'],
    bell: ['M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4'],
    book: ['M4 3h13a3 3 0 0 1 3 3v15H6a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3M3 17h17M8 7h7M8 11h5'],
    users: [
        'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M22 21v-2a4 4 0 0 0-3-3.87',
        'M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0M17 3a4 4 0 0 1 0 8',
    ],
    building: ['M4 21V3h12v18M16 9h4v12M2 21h20M8 7h4M8 11h4M8 15h4M8 21v-2h4v2'],
    layers: ['M12 3 2 8l10 5 10-5zM2 12l10 5 10-5M2 16l10 5 10-5'],
    search: ['M21 21l-5-5', 'M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0'],
    plus: ['M12 5v14M5 12h14'],
    close: ['M6 6l12 12M6 18 18 6'],
    check: ['M5 12l4 4L19 6'],
    arrow: ['M5 12h14M13 6l6 6-6 6'],
    back: ['M19 12H5M11 6l-6 6 6 6'],
    chevron: ['M9 5l7 7-7 7'],
    edit: ['m16 3 5 5-12 12-6 1 1-6zM14 5l5 5'],
    refresh: ['M20 7V3l-4 4M20 7a9 9 0 1 0 1 9'],
    clock: ['M12 8v4l3 2', 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0'],
    calendar: ['M4 5h16v16H4zM8 3v4M16 3v4M4 10h16'],
    alert: ['m12 3 10 18H2zM12 9v4M12 17h.01'],
    menu: ['M4 6h16M4 12h16M4 18h16'],
    filter: ['M4 5h16M7 12h10M10 19h4'],
    logo: ['M5 7h14v4H9v6H5zM11 13h8v4h-8z'],
}
</script>
<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.7"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path v-for="(path, index) in paths[name] || paths.tickets" :key="index" :d="path" />
    </svg>
</template>
