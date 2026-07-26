<script setup>
import { usePage, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);

function logout() {
  router.post('/logout');
}
</script>

<template>
  <nav class="flex items-center justify-between border-b bg-white px-6 py-3">
    <span class="text-lg font-semibold text-gray-900">CMOP</span>
    <div v-if="user" class="flex items-center gap-4 text-sm text-gray-600">
      <span>{{ user.name }} ({{ user.roles.join(', ') }})</span>
      <button class="text-red-600 hover:underline" @click="logout">Log out</button>
    </div>
  </nav>
</template>
