<script setup lang="ts">
import type { NuxtError } from '#app'

const props = defineProps<{
  error: NuxtError
}>()

const { t: $t } = useI18n()

const errorConfig = computed(() => {
  const code = props.error.statusCode || 500
  if (code === 404) {
    return {
      icon: 'i-lucide-search-x',
      title: $t('error.notFound'),
      description: $t('error.notFoundDesc'),
    }
  }
  if (code === 401) {
    return {
      icon: 'i-lucide-lock',
      title: $t('error.unauthorized'),
      description: $t('error.unauthorizedDesc'),
    }
  }
  if (code === 403) {
    return {
      icon: 'i-lucide-shield-x',
      title: $t('error.forbidden'),
      description: $t('error.forbiddenDesc'),
    }
  }
  return {
    icon: 'i-lucide-alert-triangle',
    title: $t('error.serverError'),
    description: $t('error.serverErrorDesc'),
  }
})

function handleError() {
  clearError({ redirect: '/' })
}
</script>

<template>
  <UApp>
    <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-950 dark:to-gray-900">
      <UEmpty
        :icon="errorConfig.icon"
        :title="errorConfig.title"
        :description="errorConfig.description"
      >
        <template #actions>
          <UButton icon="i-lucide-home" @click="handleError">
            {{ $t('error.backHome') }}
          </UButton>
        </template>
      </UEmpty>
    </div>
  </UApp>
</template>
