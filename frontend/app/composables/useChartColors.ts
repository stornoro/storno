export function useChartColors() {
  const colorMode = useColorMode()

  const isDark = computed(() => colorMode.value === 'dark')

  const textColor = computed(() => isDark.value ? '#E5E7EB' : '#374151')
  const mutedColor = computed(() => isDark.value ? '#9CA3AF' : '#6B7280')
  const gridColor = computed(() => isDark.value ? '#374151' : '#E5E7EB')
  const bgColor = computed(() => isDark.value ? '#1F2937' : '#FFFFFF')
  const borderColor = computed(() => isDark.value ? '#4B5563' : '#E5E7EB')

  const chartColors = {
    incoming: 'oklch(62.3% 0.214 259.815)',
    outgoing: 'oklch(72.3% 0.219 149.579)',
    primary: 'oklch(62.3% 0.214 259.815)',
    success: 'oklch(72.3% 0.219 149.579)',
    warning: 'oklch(79.5% 0.184 86.047)',
    error: 'oklch(63.7% 0.237 25.331)',
    info: 'oklch(62.3% 0.214 259.815)',
    neutral: 'oklch(70.4% 0.04 256.788)',
  }

  const defaultChartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        labels: {
          color: textColor.value,
          usePointStyle: true,
          pointStyle: 'circle' as const,
          padding: 16,
          font: { size: 12 },
        },
      },
      tooltip: {
        backgroundColor: bgColor.value,
        titleColor: textColor.value,
        bodyColor: textColor.value,
        borderColor: borderColor.value,
        borderWidth: 1,
      },
    },
    scales: {
      x: {
        ticks: { color: mutedColor.value },
        grid: { color: gridColor.value },
      },
      y: {
        ticks: { color: mutedColor.value },
        grid: { color: gridColor.value },
      },
    },
  }))

  return {
    colorMode,
    isDark,
    textColor,
    mutedColor,
    gridColor,
    bgColor,
    borderColor,
    chartColors,
    defaultChartOptions,
  }
}
