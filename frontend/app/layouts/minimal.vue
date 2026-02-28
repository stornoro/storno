<template>
  <div class="min-h-screen flex items-center justify-center bg-(--ui-bg) relative overflow-hidden">
    <!-- Decorative background: floating accounting icons -->
    <div class="absolute inset-0 pointer-events-none select-none" aria-hidden="true">
      <!-- Row 1 -->
      <UIcon name="i-lucide-receipt" class="bg-icon" style="left:4%;top:7%;font-size:2.2rem;animation:float-a 18s ease-in-out infinite;opacity:0.16;" />
      <UIcon name="i-lucide-calculator" class="bg-icon" style="left:16%;top:3%;font-size:1.5rem;animation:float-b 22s ease-in-out infinite;opacity:0.14;" />
      <UIcon name="i-lucide-file-text" class="bg-icon" style="left:30%;top:9%;font-size:2.8rem;animation:float-c 26s ease-in-out infinite;opacity:0.12;" />
      <UIcon name="i-lucide-percent" class="bg-icon" style="left:47%;top:4%;font-size:1.8rem;animation:float-a 20s ease-in-out infinite 3s;opacity:0.18;" />
      <UIcon name="i-lucide-bar-chart-2" class="bg-icon" style="left:62%;top:8%;font-size:2.4rem;animation:float-b 24s ease-in-out infinite 1s;opacity:0.16;" />
      <UIcon name="i-lucide-wallet" class="bg-icon" style="left:76%;top:3%;font-size:1.6rem;animation:float-c 19s ease-in-out infinite 5s;opacity:0.14;" />
      <UIcon name="i-lucide-coins" class="bg-icon" style="left:89%;top:7%;font-size:2rem;animation:float-a 23s ease-in-out infinite 2s;opacity:0.20;" />

      <!-- Row 2 -->
      <UIcon name="i-lucide-credit-card" class="bg-icon" style="left:8%;top:24%;font-size:3rem;animation:float-b 28s ease-in-out infinite 4s;opacity:0.12;" />
      <UIcon name="i-lucide-trending-up" class="bg-icon" style="left:22%;top:28%;font-size:1.7rem;animation:float-c 21s ease-in-out infinite 1s;opacity:0.16;" />
      <UIcon name="i-lucide-landmark" class="bg-icon" style="left:37%;top:22%;font-size:2.5rem;animation:float-a 25s ease-in-out infinite 6s;opacity:0.12;" />
      <UIcon name="i-lucide-piggy-bank" class="bg-icon" style="left:54%;top:26%;font-size:2rem;animation:float-b 17s ease-in-out infinite;opacity:0.18;" />
      <UIcon name="i-lucide-file-spreadsheet" class="bg-icon" style="left:69%;top:21%;font-size:1.4rem;animation:float-c 29s ease-in-out infinite 3s;opacity:0.14;" />
      <UIcon name="i-lucide-dollar-sign" class="bg-icon" style="left:82%;top:27%;font-size:2.8rem;animation:float-a 20s ease-in-out infinite 7s;opacity:0.12;" />
      <UIcon name="i-lucide-stamp" class="bg-icon" style="left:94%;top:22%;font-size:1.9rem;animation:float-b 22s ease-in-out infinite 2s;opacity:0.16;" />

      <!-- Row 3 -->
      <UIcon name="i-lucide-building-2" class="bg-icon" style="left:2%;top:44%;font-size:1.5rem;animation:float-c 27s ease-in-out infinite 5s;opacity:0.14;" />
      <UIcon name="i-lucide-receipt-text" class="bg-icon" style="left:14%;top:48%;font-size:2.3rem;animation:float-a 19s ease-in-out infinite 1s;opacity:0.18;" />
      <UIcon name="i-lucide-scale" class="bg-icon" style="left:26%;top:42%;font-size:1.8rem;animation:float-b 24s ease-in-out infinite 4s;opacity:0.12;" />
      <!-- centre gap preserved for the card -->
      <UIcon name="i-lucide-chart-pie" class="bg-icon" style="left:71%;top:46%;font-size:2.6rem;animation:float-a 21s ease-in-out infinite 6s;opacity:0.14;" />
      <UIcon name="i-lucide-banknote" class="bg-icon" style="left:84%;top:41%;font-size:1.7rem;animation:float-c 23s ease-in-out infinite;opacity:0.16;" />
      <UIcon name="i-lucide-handshake" class="bg-icon" style="left:95%;top:47%;font-size:2.1rem;animation:float-b 26s ease-in-out infinite 3s;opacity:0.12;" />

      <!-- Row 4 -->
      <UIcon name="i-lucide-clipboard-list" class="bg-icon" style="left:6%;top:64%;font-size:2rem;animation:float-b 20s ease-in-out infinite 2s;opacity:0.16;" />
      <UIcon name="i-lucide-arrow-up-down" class="bg-icon" style="left:18%;top:68%;font-size:1.4rem;animation:float-c 25s ease-in-out infinite 5s;opacity:0.14;" />
      <UIcon name="i-lucide-briefcase" class="bg-icon" style="left:32%;top:62%;font-size:2.7rem;animation:float-a 18s ease-in-out infinite 3s;opacity:0.12;" />
      <UIcon name="i-lucide-file-check" class="bg-icon" style="left:57%;top:66%;font-size:1.9rem;animation:float-b 29s ease-in-out infinite 1s;opacity:0.16;" />
      <UIcon name="i-lucide-lock" class="bg-icon" style="left:72%;top:63%;font-size:1.5rem;animation:float-c 22s ease-in-out infinite 6s;opacity:0.14;" />
      <UIcon name="i-lucide-tag" class="bg-icon" style="left:86%;top:68%;font-size:2.4rem;animation:float-a 27s ease-in-out infinite;opacity:0.18;" />

      <!-- Row 5 -->
      <UIcon name="i-lucide-inbox" class="bg-icon" style="left:3%;top:82%;font-size:1.6rem;animation:float-c 21s ease-in-out infinite 4s;opacity:0.16;" />
      <UIcon name="i-lucide-send" class="bg-icon" style="left:15%;top:87%;font-size:2.2rem;animation:float-a 23s ease-in-out infinite 1s;opacity:0.12;" />
      <UIcon name="i-lucide-calculator" class="bg-icon" style="left:28%;top:80%;font-size:1.7rem;animation:float-b 18s ease-in-out infinite 7s;opacity:0.14;" />
      <UIcon name="i-lucide-coins" class="bg-icon" style="left:43%;top:85%;font-size:2.9rem;animation:float-c 26s ease-in-out infinite 2s;opacity:0.12;" />
      <UIcon name="i-lucide-percent" class="bg-icon" style="left:59%;top:81%;font-size:1.5rem;animation:float-a 20s ease-in-out infinite 5s;opacity:0.16;" />
      <UIcon name="i-lucide-trending-up" class="bg-icon" style="left:74%;top:86%;font-size:2rem;animation:float-b 28s ease-in-out infinite;opacity:0.14;" />
      <UIcon name="i-lucide-wallet" class="bg-icon" style="left:88%;top:83%;font-size:2.5rem;animation:float-c 19s ease-in-out infinite 3s;opacity:0.18;" />
      <UIcon name="i-lucide-receipt" class="bg-icon" style="left:96%;top:88%;font-size:1.8rem;animation:float-a 24s ease-in-out infinite 6s;opacity:0.12;" />
    </div>

    <div class="relative z-10 w-full max-w-lg px-4 py-12">
      <!-- Logo -->
      <div class="text-center mb-4">
        <NuxtLink to="/" class="inline-flex flex-col items-center gap-1.5">
          <img src="/logo.png" alt="Storno.ro" class="h-9 w-auto" />
          <span class="text-xl font-bold text-(--ui-text)">Storno.ro</span>
        </NuxtLink>
      </div>

      <slot />
    </div>
  </div>
</template>

<style scoped>
.bg-icon {
  position: absolute;
  color: var(--ui-primary);
  line-height: 1;
  will-change: transform;
}

@keyframes float-a {
  0%   { transform: translateY(0px)   translateX(0px)   rotate(0deg); }
  25%  { transform: translateY(-14px) translateX(6px)   rotate(3deg); }
  50%  { transform: translateY(-8px)  translateX(-4px)  rotate(-2deg); }
  75%  { transform: translateY(-18px) translateX(8px)   rotate(4deg); }
  100% { transform: translateY(0px)   translateX(0px)   rotate(0deg); }
}

@keyframes float-b {
  0%   { transform: translateY(0px)   translateX(0px)  rotate(0deg); }
  20%  { transform: translateY(-10px) translateX(-10px) rotate(-3deg); }
  50%  { transform: translateY(-20px) translateX(5px)   rotate(2deg); }
  80%  { transform: translateY(-6px)  translateX(12px)  rotate(-4deg); }
  100% { transform: translateY(0px)   translateX(0px)  rotate(0deg); }
}

@keyframes float-c {
  0%   { transform: translateY(0px)   rotate(0deg); }
  33%  { transform: translateY(-22px) rotate(2deg); }
  66%  { transform: translateY(-10px) rotate(-1deg); }
  100% { transform: translateY(0px)   rotate(0deg); }
}
</style>
