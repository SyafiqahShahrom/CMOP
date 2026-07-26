<script setup>
import NavBar from '@/Components/NavBar.vue';
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <NavBar />
    <main class="mx-auto max-w-7xl px-4 py-6">
      <slot />
    </main>
  </div>
</template>
