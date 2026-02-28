export default defineNuxtConfig({
  devServer: {
    https: {
      key: './storno.test-key.pem',
      cert: './storno.test.pem',
    },
    host: 'app.storno.test',
  },

  modules: [
    '@nuxt/ui',
    '@pinia/nuxt',
    '@nuxtjs/i18n',
    '@vueuse/nuxt',
    '@nuxtjs/turnstile',
  ],

  turnstile: {
    siteKey: process.env.NUXT_PUBLIC_TURNSTILE_SITE_KEY || '1x00000000000000000000AA',
  },

  devtools: { enabled: true },

  runtimeConfig: {
    chatwootIdentityToken: '',
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'https://api.storno.ro',
      centrifugoWs: process.env.NUXT_PUBLIC_CENTRIFUGO_WS || 'wss://app.storno.test:8444/connection/websocket',
      googleClientId: process.env.NUXT_PUBLIC_GOOGLE_CLIENT_ID || '',
      chatwootBaseUrl: process.env.NUXT_PUBLIC_CHATWOOT_BASE_URL || '',
      chatwootWebsiteToken: process.env.NUXT_PUBLIC_CHATWOOT_WEBSITE_TOKEN || '',
    },
  },

  routeRules: {
    '/api/**': {
      proxy: (process.env.API_BACKEND_URL || 'http://api.storno.test:8000') + '/api/**',
    },
  },

  icon: {
    localApiEndpoint: '/_nuxt_icon',
    serverBundle: 'local',
    clientBundle: {
      icons: [],
      scan: true,
    },
  },

  i18n: {
    locales: [
      { code: 'ro', name: 'Romana', file: 'ro.ts' },
    ],
    defaultLocale: 'ro',
    strategy: 'no_prefix',
    lazy: true,
    langDir: '../i18n/',
    bundle: {
      optimizeTranslationDirective: false,
    },
  },

  components: [
    { path: '~/components/common', pathPrefix: false },
    '~/components',
  ],

  css: ['~/assets/css/main.css'],

  experimental: {
    appManifest: false,
  },

  compatibilityDate: '2025-01-01',
})
