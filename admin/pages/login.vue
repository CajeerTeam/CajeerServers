<script setup lang="ts">
definePageMeta({ layout: 'auth' })
const auth = useAuth()
const email = ref('')
const password = ref('')
const mfa = ref('')
const busy = ref(false)
const error = ref('')
async function submit() {
  busy.value = true; error.value = ''
  try { await auth.signIn(email.value.trim(), password.value, mfa.value); password.value = ''; mfa.value = ''; await navigateTo('/', { replace: true }) }
  catch (e) { error.value = apiError(e).message; password.value = '' }
  finally { busy.value = false }
}
</script>
<template>
  <section class="auth-box">
    <div class="brand">CajeerServers</div>
    <h1>Вход в панель</h1><p class="muted">Авторизация через защищённую серверную сессию.</p>
    <form @submit.prevent="submit">
      <div class="field"><label for="email">Email</label><input id="email" v-model="email" type="email" autocomplete="username" required maxlength="255" /></div>
      <div class="field"><label for="password">Пароль</label><input id="password" v-model="password" type="password" autocomplete="current-password" required /></div>
      <div class="field"><label for="mfa">Код TOTP или резервный код (если включена 2FA)</label><input id="mfa" v-model="mfa" inputmode="text" autocomplete="one-time-code" maxlength="64" /></div>
      <UiAlert :message="error" />
      <button class="btn primary" :disabled="busy" type="submit">{{ busy ? 'Проверка…' : 'Войти' }}</button>
    </form>
  </section>
</template>
