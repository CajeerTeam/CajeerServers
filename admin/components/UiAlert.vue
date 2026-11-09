<script setup lang="ts">
defineProps<{ message?: string | null; tone?: 'error' | 'info' | 'success' }>()
</script>
<template><p v-if="message" role="alert" :class="['alert', tone === 'info' ? 'info' : tone === 'success' ? 'success' : '']">{{ message }}</p></template>
