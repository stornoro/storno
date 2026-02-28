<template>
  <USlideover v-model:open="open" :ui="{ content: 'sm:max-w-lg' }">
    <template #header>
      <div class="flex items-center justify-between w-full">
        <h3 class="text-lg font-semibold">{{ $t('settings.teamManagement.editMember') }}</h3>
        <div class="flex items-center gap-2">
          <USwitch v-model="form.isActive" size="sm" />
          <span class="text-sm text-(--ui-text-muted)">{{ $t('settings.teamManagement.activeStatus') }}</span>
        </div>
      </div>
    </template>
    <template #body>
      <div v-if="member" class="space-y-4">
        <div class="flex items-center gap-3 rounded-lg bg-(--ui-bg-elevated)/50 p-3">
          <UIcon name="i-lucide-user" class="size-5 text-(--ui-text-muted)" />
          <div class="text-sm">
            <p class="font-medium">{{ getFullName(member) }}</p>
            <p class="text-(--ui-text-muted)">{{ member.user.email }}</p>
          </div>
        </div>

        <UFormField :label="$t('settings.role')">
          <USelectMenu v-model="form.role" :items="roleOptions" value-key="value" />
        </UFormField>

        <UFormField
          v-if="showCompanyRestriction"
          :label="$t('settings.teamManagement.selectCompanies')"
          :help="$t('settings.teamManagement.selectCompaniesHelp')"
        >
          <USelectMenu
            v-model="form.allowedCompanies"
            :items="companyOptions"
            value-key="value"
            multiple
            :search-input="{ placeholder: $t('common.search') + '...' }"
            :placeholder="$t('settings.teamManagement.allCompanies')"
          />
        </UFormField>

        <!-- Custom permissions toggle -->
        <div class="flex items-center justify-between rounded-lg bg-(--ui-bg-elevated)/50 p-3">
          <div class="flex-1 mr-3">
            <p class="text-sm font-medium">{{ $t('settings.teamManagement.customPermissions') }}</p>
            <p class="text-xs text-(--ui-text-muted)">{{ $t('settings.teamManagement.customPermissionsHelp') }}</p>
          </div>
          <USwitch v-model="form.useCustomPermissions" size="sm" />
        </div>

        <!-- Permission checkboxes -->
        <div v-if="permissionsRef" class="space-y-3 max-h-[50vh] overflow-y-auto">
          <div v-for="(perms, category) in permissionsRef.permissions" :key="category" class="space-y-1">
            <button
              type="button"
              class="flex items-center gap-2 text-sm font-medium text-(--ui-text-highlighted) hover:text-(--ui-text) cursor-pointer"
              :class="{ 'opacity-50 pointer-events-none': !form.useCustomPermissions }"
              @click="toggleCategory(category)"
            >
              <div
                class="size-4 rounded border flex items-center justify-center transition-colors"
                :class="isCategoryFullySelected(category)
                  ? 'bg-primary border-primary text-white'
                  : isCategoryPartiallySelected(category)
                    ? 'bg-primary/20 border-primary'
                    : 'border-default'"
              >
                <UIcon v-if="isCategoryFullySelected(category)" name="i-lucide-check" class="size-3" />
                <UIcon v-else-if="isCategoryPartiallySelected(category)" name="i-lucide-minus" class="size-3" />
              </div>
              {{ getCategoryLabel(category) }}
            </button>
            <div class="ml-6 space-y-0.5">
              <button
                v-for="perm in perms"
                :key="perm"
                type="button"
                class="flex items-center gap-2 text-sm text-(--ui-text-muted) hover:text-(--ui-text) w-full cursor-pointer py-0.5"
                :class="{ 'opacity-50 pointer-events-none': !form.useCustomPermissions }"
                @click="togglePermission(perm)"
              >
                <div
                  class="size-4 rounded border flex items-center justify-center transition-colors"
                  :class="form.customPermissions.includes(perm)
                    ? 'bg-primary border-primary text-white'
                    : 'border-default'"
                >
                  <UIcon v-if="form.customPermissions.includes(perm)" name="i-lucide-check" class="size-3" />
                </div>
                <span>{{ perm }}</span>
                <UIcon
                  v-if="isRoleDefault(perm)"
                  name="i-lucide-shield"
                  class="size-3 text-(--ui-text-dimmed)"
                  :title="$t('settings.role')"
                />
              </button>
            </div>
          </div>
        </div>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton variant="ghost" @click="open = false">{{ $t('common.cancel') }}</UButton>
        <UButton :loading="saving" @click="onSave">{{ $t('common.save') }}</UButton>
      </div>
    </template>
  </USlideover>
</template>

<script setup lang="ts">
import type { TeamMember } from '~/types'

const props = defineProps<{
  member: TeamMember | null
}>()

const open = defineModel<boolean>('open', { default: false })

const emit = defineEmits<{
  updated: []
}>()

const { t: $t } = useI18n()
const toast = useToast()
const teamStore = useTeamStore()
const companyStore = useCompanyStore()

const saving = ref(false)

const form = ref({
  role: 'accountant',
  isActive: true,
  allowedCompanies: [] as string[],
  useCustomPermissions: false,
  customPermissions: [] as string[],
})

const permissionsRef = computed(() => teamStore.permissionsReference)

const roleOptions = [
  { label: $t('settings.roles.owner'), value: 'owner' },
  { label: $t('settings.roles.admin'), value: 'admin' },
  { label: $t('settings.roles.accountant'), value: 'accountant' },
  { label: $t('settings.roles.employee'), value: 'employee' },
]

const companyOptions = computed(() =>
  companyStore.companies.map(c => ({
    label: `${c.name} (${c.cif})`,
    value: c.id,
  })),
)

const showCompanyRestriction = computed(() =>
  form.value.role === 'accountant' || form.value.role === 'employee',
)

const roleDefaultPerms = computed(() => {
  if (!permissionsRef.value) return []
  return permissionsRef.value.roleDefaults[form.value.role] ?? []
})

function getFullName(member: TeamMember): string {
  const name = [member.user.firstName, member.user.lastName].filter(Boolean).join(' ')
  return name || member.user.email
}

const CATEGORY_LABELS: Record<string, string> = {
  company: 'Companie',
  client: 'Clienti',
  product: 'Produse',
  invoice: 'Facturi',
  recurring_invoice: 'Facturi recurente',
  series: 'Serii documente',
  payment: 'Plati',
  efactura: 'e-Factura',
  settings: 'Setari',
  org: 'Organizatie',
  import: 'Import',
  export: 'Export',
  webhook: 'Webhook',
  api_key: 'Chei API',
  backup: 'Backup',
  email_template: 'Sabloane email',
  report: 'Rapoarte',
  borderou: 'Borderou',
}

function getCategoryLabel(category: string): string {
  const key = `settings.teamManagement.permissionCategories.${category}` as any
  const translated = $t(key)
  if (translated !== key) return translated
  return CATEGORY_LABELS[category] ?? category
}

function isRoleDefault(perm: string): boolean {
  return roleDefaultPerms.value.includes(perm)
}

function togglePermission(perm: string) {
  const idx = form.value.customPermissions.indexOf(perm)
  if (idx >= 0) {
    form.value.customPermissions.splice(idx, 1)
  }
  else {
    form.value.customPermissions.push(perm)
  }
}

function toggleCategory(category: string) {
  if (!permissionsRef.value) return
  const categoryPerms = permissionsRef.value.permissions[category] ?? []
  const allSelected = categoryPerms.every(p => form.value.customPermissions.includes(p))
  if (allSelected) {
    form.value.customPermissions = form.value.customPermissions.filter(p => !categoryPerms.includes(p))
  }
  else {
    for (const p of categoryPerms) {
      if (!form.value.customPermissions.includes(p)) {
        form.value.customPermissions.push(p)
      }
    }
  }
}

function isCategoryFullySelected(category: string): boolean {
  if (!permissionsRef.value) return false
  const categoryPerms = permissionsRef.value.permissions[category] ?? []
  return categoryPerms.length > 0 && categoryPerms.every(p => form.value.customPermissions.includes(p))
}

function isCategoryPartiallySelected(category: string): boolean {
  if (!permissionsRef.value) return false
  const categoryPerms = permissionsRef.value.permissions[category] ?? []
  const count = categoryPerms.filter(p => form.value.customPermissions.includes(p)).length
  return count > 0 && count < categoryPerms.length
}

watch(open, (isOpen) => {
  if (isOpen && props.member) {
    form.value = {
      role: props.member.role,
      isActive: props.member.isActive,
      allowedCompanies: props.member.allowedCompanies.map(c => c.id),
      useCustomPermissions: props.member.hasCustomPermissions,
      customPermissions: props.member.hasCustomPermissions
        ? [...props.member.permissions]
        : [...(teamStore.permissionsReference?.roleDefaults[props.member.role] ?? [])],
    }
    if (!teamStore.permissionsReference) {
      teamStore.fetchPermissionsReference()
    }
  }
})

// When toggling custom permissions off, reset to role defaults
watch(() => form.value.useCustomPermissions, (useCustom) => {
  if (!useCustom) {
    form.value.customPermissions = [...roleDefaultPerms.value]
  }
})

// When role changes and custom permissions is off, update to new role defaults
watch(() => form.value.role, () => {
  if (!form.value.useCustomPermissions) {
    form.value.customPermissions = [...roleDefaultPerms.value]
  }
})

async function onSave() {
  if (!props.member) return

  saving.value = true
  const data: Record<string, any> = {
    role: form.value.role,
    isActive: form.value.isActive,
  }

  if (showCompanyRestriction.value) {
    data.allowedCompanies = form.value.allowedCompanies
  }

  if (form.value.useCustomPermissions) {
    data.permissions = form.value.customPermissions
  }
  else {
    data.permissions = null
  }

  const ok = await teamStore.updateMember(props.member.id, data)
  saving.value = false

  if (ok) {
    emit('updated')
  }
  else if (teamStore.error) {
    toast.add({ title: teamStore.error, color: 'error' })
  }
}
</script>
