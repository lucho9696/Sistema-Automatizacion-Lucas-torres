<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { login } from '../session';
import { errorMessage } from '../api';
import ErrorBox from '../components/ErrorBox.vue';

const router = useRouter();
const email = ref('');
const password = ref('');
const error = ref<string | null>(null);
const busy = ref(false);

async function submit() {
    busy.value = true;
    error.value = null;
    try {
        await login(email.value, password.value);
        router.push('/');
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <form class="card mx-auto mt-16 max-w-sm space-y-3" @submit.prevent="submit">
        <h1 class="text-lg font-semibold">Ingresar</h1>
        <ErrorBox :message="error" />
        <div>
            <label class="label" for="email">Email</label>
            <input id="email" v-model="email" class="input" type="email" autocomplete="username" required />
        </div>
        <div>
            <label class="label" for="password">Contraseña</label>
            <input id="password" v-model="password" class="input" type="password" autocomplete="current-password" required />
        </div>
        <button class="btn w-full justify-center" :disabled="busy">Ingresar</button>
    </form>
</template>
