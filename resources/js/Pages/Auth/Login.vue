<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({
  email: '',
  password: '',
});

function submit() {
  form.post('/login');
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-gray-50">
    <form class="w-full max-w-sm space-y-4 rounded border bg-white p-6 shadow-sm" @submit.prevent="submit">
      <h1 class="text-xl font-semibold text-gray-900">CMOP Sign In</h1>

      <div>
        <label class="block text-sm text-gray-700" for="email">Email</label>
        <input id="email" v-model="form.email" type="email" class="mt-1 w-full rounded border px-3 py-2" />
        <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
      </div>

      <div>
        <label class="block text-sm text-gray-700" for="password">Password</label>
        <input id="password" v-model="form.password" type="password" class="mt-1 w-full rounded border px-3 py-2" />
      </div>

      <button type="submit" class="w-full rounded bg-gray-900 px-4 py-2 text-white" :disabled="form.processing">
        Sign in
      </button>
    </form>
  </div>
</template>
