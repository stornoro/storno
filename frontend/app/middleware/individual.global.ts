/** A natural person's company has no selling pages: send them back to the dashboard. */
export default defineNuxtRouteMiddleware((to) => {
  const companyStore = useCompanyStore()
  if (!companyStore.currentCompany?.isIndividual) return
  if (isHiddenForIndividual(to.path)) {
    return navigateTo('/dashboard')
  }
})
