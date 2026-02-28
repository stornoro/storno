<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

defineProps<{
  collapsed?: boolean
}>()

const { t: $t } = useI18n()
const authStore = useAuthStore()
const colorMode = useColorMode()
const appConfig = useAppConfig()

const primaryColors = ['red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose']
const neutralColors = ['slate', 'gray', 'zinc', 'neutral', 'stone']

const items = computed<DropdownMenuItem[][]>(() => [[{
  type: 'label',
  label: authStore.fullName || authStore.user?.email || '',
}], [{
  label: $t('nav.settings'),
  icon: 'i-lucide-settings',
  to: '/settings/profile',
}], [{
  label: $t('theme.title'),
  icon: 'i-lucide-palette',
  children: [{
    label: $t('theme.primary'),
    slot: 'chip',
    chip: appConfig.ui.colors.primary,
    content: {
      align: 'center',
      collisionPadding: 16,
    },
    children: primaryColors.map(color => ({
      label: color,
      chip: color,
      slot: 'chip',
      checked: appConfig.ui.colors.primary === color,
      type: 'checkbox' as const,
      onSelect: (e: Event) => {
        e.preventDefault()
        appConfig.ui.colors.primary = color
      },
    })),
  }, {
    label: $t('theme.neutral'),
    slot: 'chip',
    chip: appConfig.ui.colors.neutral === 'neutral' ? 'old-neutral' : appConfig.ui.colors.neutral,
    content: {
      align: 'end',
      collisionPadding: 16,
    },
    children: neutralColors.map(color => ({
      label: color,
      chip: color === 'neutral' ? 'old-neutral' : color,
      slot: 'chip',
      type: 'checkbox' as const,
      checked: appConfig.ui.colors.neutral === color,
      onSelect: (e: Event) => {
        e.preventDefault()
        appConfig.ui.colors.neutral = color
      },
    })),
  }],
}, {
  label: $t('theme.appearance'),
  icon: 'i-lucide-sun-moon',
  children: [{
    label: $t('theme.light'),
    icon: 'i-lucide-sun',
    type: 'checkbox',
    checked: colorMode.value === 'light',
    onSelect(e: Event) {
      e.preventDefault()
      colorMode.preference = 'light'
    },
  }, {
    label: $t('theme.dark'),
    icon: 'i-lucide-moon',
    type: 'checkbox',
    checked: colorMode.value === 'dark',
    onSelect(e: Event) {
      e.preventDefault()
      colorMode.preference = 'dark'
    },
  }],
}], [{
  label: $t('auth.logout'),
  icon: 'i-lucide-log-out',
  onSelect: () => {
    authStore.logout()
    navigateTo('/login')
  },
}]])
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'center', collisionPadding: 12 }"
    :ui="{ content: collapsed ? 'w-48' : 'w-(--reka-dropdown-menu-trigger-width)' }"
  >
    <UButton
      v-bind="{
        label: collapsed ? undefined : authStore.fullName,
        trailingIcon: collapsed ? undefined : 'i-lucide-chevrons-up-down',
      }"
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      class="data-[state=open]:bg-elevated"
      :ui="{
        trailingIcon: 'text-dimmed',
      }"
    >
      <template #leading>
        <UAvatar :text="authStore.initials" size="2xs" />
      </template>
    </UButton>

    <template #chip-leading="{ item }">
      <div class="inline-flex items-center justify-center shrink-0 size-5">
        <span
          class="rounded-full ring ring-bg bg-(--chip-light) dark:bg-(--chip-dark) size-2"
          :style="{
            '--chip-light': `var(--color-${(item as any).chip}-500)`,
            '--chip-dark': `var(--color-${(item as any).chip}-400)`,
          }"
        />
      </div>
    </template>
  </UDropdownMenu>
</template>
