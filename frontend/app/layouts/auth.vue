<template>
  <div class="min-h-screen flex">
    <!-- Left branding panel (hidden on mobile) -->
    <div class="hidden lg:flex lg:w-1/2 bg-primary/5 dark:bg-primary/10 relative overflow-hidden items-center justify-center p-12">
      <div class="relative z-10 max-w-md text-center space-y-8">
        <img src="/logo.png" alt="Storno.ro" class="h-16 w-auto mx-auto" />
        <div>
          <h1 class="text-3xl font-extrabold tracking-tight text-(--ui-text)">Storno.ro</h1>
          <p class="mt-2 text-lg text-(--ui-text-muted)">{{ $t('app.tagline') }}</p>
        </div>
        <div class="grid grid-cols-3 gap-4 pt-4">
          <div class="text-center">
            <div class="text-2xl font-bold text-primary">e-Factura</div>
            <div class="text-xs text-(--ui-text-muted) mt-1">ANAF SPV</div>
          </div>
          <div class="text-center">
            <div class="text-2xl font-bold text-primary">Multi</div>
            <div class="text-xs text-(--ui-text-muted) mt-1">{{ $t('landing.feature2Title') }}</div>
          </div>
          <div class="text-center">
            <div class="text-2xl font-bold text-primary">PDF</div>
            <div class="text-xs text-(--ui-text-muted) mt-1">& XML</div>
          </div>
        </div>
      </div>
      <!-- Decorative circles -->
      <div class="absolute -top-24 -left-24 w-96 h-96 bg-primary/5 rounded-full" />
      <div class="absolute -bottom-32 -right-32 w-[500px] h-[500px] bg-primary/5 rounded-full" />
    </div>

    <!-- Right form panel -->
    <div class="flex-1 flex items-center justify-center px-4 py-12 bg-(--ui-bg)">
      <div class="w-full max-w-md">
        <!-- Mobile-only logo -->
        <div class="lg:hidden text-center mb-8">
          <NuxtLink to="/" class="inline-flex flex-col items-center gap-3">
            <img src="/logo.png" alt="Storno.ro" class="h-12 w-auto" />
            <span class="text-2xl font-bold text-(--ui-text)">Storno.ro</span>
          </NuxtLink>
        </div>
        <slot />
      </div>
    </div>
  </div>
</template>
