<script setup lang="ts">
defineProps<{ label: string; value: string; hint?: string }>();
</script>

<template>
    <div class="card">
        <div class="text-xs font-medium uppercase text-slate-500">{{ label }}</div>
        <div class="mt-1 text-xl font-semibold tabular-nums">{{ value }}</div>
        <div v-if="hint" class="mt-1 text-xs text-slate-500">{{ hint }}</div>
    </div>
</template>
