<template>
  <UModal v-model:open="open">
    <template #header>
      <h3 class="font-semibold">{{ $t('settings.inviteMember') }}</h3>
    </template>
    <template #body>
      <div class="space-y-4">
        <UFormField :label="$t('settings.invitations.emailLabel')">
          <UInput v-model="form.email" type="email" placeholder="email@exemplu.ro" />
        </UFormField>
        <UFormField :label="$t('settings.invitations.roleLabel')">
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
      </div>
      <p v-if="errorMessage" class="mt-3 text-sm text-error">{{ errorMessage }}</p>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton variant="ghost" @click="open = false">{{ $t('common.cancel') }}</UButton>
        <UButton :loading="saving" @click="onSubmit">{{ $t('settings.inviteMember') }}</UButton>
      </div>
    </template>
  </UModal>
</template>

<script setup lang="ts">
const open = defineModel<boolean>('open', { default: false })

const emit = defineEmits<{
  created: []
}>()

const { t: $t } = useI18n()
const teamStore = useTeamStore()
const companyStore = useCompanyStore()

const saving = ref(false)
const errorMessage = ref('')

const form = ref({
  email: '',
  role: 'accountant',
  allowedCompanies: [] as string[],
})

const roleOptions = [
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

watch(open, (isOpen) => {
  if (isOpen) {
    form.value = { email: '', role: 'accountant', allowedCompanies: [] }
    errorMessage.value = ''
  }
})

async function onSubmit() {
  errorMessage.value = ''
  if (!form.value.email) {
    errorMessage.value = $t('validation.required')
    return
  }

  saving.value = true
  const payload: Record<string, any> = {
    email: form.value.email,
    role: form.value.role,
  }

  if (showCompanyRestriction.value && form.value.allowedCompanies.length > 0) {
    payload.allowedCompanies = form.value.allowedCompanies
  }

  const result = await teamStore.createInvitation(payload as any)
  saving.value = false

  if (result) {
    emit('created')
  }
  else {
    errorMessage.value = $t('settings.invitations.createError')
  }
}
</script>
