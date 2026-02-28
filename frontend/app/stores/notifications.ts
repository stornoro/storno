import { defineStore } from 'pinia'

export type NotificationType = 'success' | 'error' | 'warning' | 'info'

export interface Notification {
  id: string
  type: NotificationType
  title: string
  description?: string
  duration?: number
  dismissible?: boolean
}

const DEFAULT_DURATION = 5000

let idCounter = 0

function generateId(): string {
  return `notification-${Date.now()}-${++idCounter}`
}

// Timers live outside the store so Pinia doesn't try to serialize them during SSR
const timers = new Map<string, ReturnType<typeof setTimeout>>()

export const useNotificationStore = defineStore('notifications', () => {
  // ── State ──────────────────────────────────────────────────────────
  const notifications = ref<Notification[]>([])

  // ── Getters ────────────────────────────────────────────────────────
  const hasNotifications = computed(() => notifications.value.length > 0)
  const count = computed(() => notifications.value.length)

  // ── Actions ────────────────────────────────────────────────────────
  function addNotification(notification: Omit<Notification, 'id'> & { id?: string }): string {
    const id = notification.id ?? generateId()
    const duration = notification.duration ?? DEFAULT_DURATION
    const dismissible = notification.dismissible ?? true

    const entry: Notification = {
      ...notification,
      id,
      duration,
      dismissible,
    }

    notifications.value.push(entry)

    // Auto-remove after duration (0 means persistent)
    if (duration > 0) {
      const timer = setTimeout(() => {
        removeNotification(id)
      }, duration)
      timers.set(id, timer)
    }

    return id
  }

  function removeNotification(id: string) {
    const timer = timers.get(id)
    if (timer) {
      clearTimeout(timer)
      timers.delete(id)
    }

    notifications.value = notifications.value.filter(n => n.id !== id)
  }

  function clearAll() {
    timers.forEach(timer => clearTimeout(timer))
    timers.clear()
    notifications.value = []
  }

  // ── Convenience shortcuts ──────────────────────────────────────────
  function success(title: string, description?: string) {
    return addNotification({ type: 'success', title, description })
  }

  function error(title: string, description?: string) {
    return addNotification({ type: 'error', title, description, duration: 8000 })
  }

  function warning(title: string, description?: string) {
    return addNotification({ type: 'warning', title, description, duration: 6000 })
  }

  function info(title: string, description?: string) {
    return addNotification({ type: 'info', title, description })
  }

  return {
    // State
    notifications,

    // Getters
    hasNotifications,
    count,

    // Actions
    addNotification,
    removeNotification,
    clearAll,

    // Shortcuts
    success,
    error,
    warning,
    info,
  }
})
