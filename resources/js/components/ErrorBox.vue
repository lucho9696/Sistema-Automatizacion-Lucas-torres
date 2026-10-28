<script setup lang="ts">
defineProps<{ message: string | null }>();
</script>

<template>
    <div v-if="message" class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
        {{ message }}
    </div>
</template>
