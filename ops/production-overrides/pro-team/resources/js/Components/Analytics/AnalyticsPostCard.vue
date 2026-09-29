<script setup>
import { computed, ref } from 'vue'
import { formatNumber } from '@/helpers'
import Badge from '@/Components/DataDisplay/Badge.vue'
import MediaPreview from '@/Components/Media/MediaPreview.vue'
import AnalyticsPostActions from '@/Components/Analytics/AnalyticsPostActions.vue'
import usePostTypeLabels from '@/Composables/usePostTypeLabels'
import MagnifyingGlassIcon from '@/Icons/MagnifyingGlass.vue'

const props = defineProps({
  post: {
    type: Object,
    required: true
  },
  columns: {
    type: Array,
    default: () => []
  },
  primaryMetric: {
    type: String,
    default: null
  },
  performanceLabel: {
    type: Object,
    default: null
  },
  showContentType: {
    type: Boolean,
    default: false
  },
  showPerformance: {
    type: Boolean,
    default: false
  },
  selected: {
    type: Boolean,
    default: false
  }
})

defineEmits(['open'])

const { postTypeLabel } = usePostTypeLabels()

const showPreview = ref(false)

const primaryMetricKey = computed(() => props.primaryMetric || props.columns[0]?.key || '')
const hasPrimaryMetric = computed(() => props.post.metrics?.[primaryMetricKey.value] != null)

// An imported post can carry no text at all, and a blank row reads as a broken one.
const isEmpty = computed(() => !props.post.title?.trim() && !props.post.text?.trim())

// The row's own tint is translucent, so a frozen cell repeating it would stack two layers and read
// darker than the columns beside it — and would let them show through as they scroll under. The
// flattened token lands on the same colour while staying opaque.
const frozenBackground = computed(() =>
  props.selected
    ? 'bg-container-neutral-solid'
    : 'bg-canvas group-hover/row:bg-container-neutral-solid'
)
</script>
<template>
  <!-- Every width here mirrors the head in AnalyticsContentList — that is what keeps the titles
       over their columns and the frozen cells over one another. -->
  <li
    role="button"
    tabindex="0"
    class="group/row flex items-stretch w-max min-w-full cursor-pointer select-none transition-colors ease-in-out duration-200"
    :class="selected ? 'bg-container-neutral' : 'hover:bg-container-neutral'"
    @click="$emit('open')"
    @keydown.enter="$emit('open')"
    @keydown.space.prevent="$emit('open')"
  >
    <!-- Frozen: the columns beside it scroll underneath, so it carries an opaque background that
         has to track the row's own hover. -->
    <div
      class="sticky start-0 z-10 grow shrink-0 w-[17rem] sm:w-[22rem] lg:w-[28rem] max-w-[50%] flex items-center gap-s ps-base pe-base py-s transition-colors ease-in-out duration-200"
      :class="frozenBackground"
    >
      <div class="flex-1 min-w-0 text-left">
        <p v-if="post.title" class="text-sm font-medium truncate">{{ post.title }}</p>

        <p
          class="text-sm truncate"
          :class="{
            'text-fg-neutral-secondary italic': isEmpty,
            'text-fg-neutral-secondary': post.title
          }"
        >
          {{ isEmpty ? $t('post.empty_post') : post.text }}
        </p>
      </div>

      <div
        v-if="post.thumbnail"
        class="group shrink-0 relative w-10 h-10 cursor-pointer"
        @click.stop="showPreview = true"
      >
        <img
          :src="post.thumbnail"
          :alt="post.text"
          loading="lazy"
          class="w-10 h-10 rounded-square aspect-square object-cover object-center bg-surface"
        />

        <div
          class="absolute inset-0 flex items-center justify-center rounded-default bg-black/50 opacity-0 transition-opacity duration-200 group-hover:opacity-100"
        >
          <MagnifyingGlassIcon class="w-4! h-4! text-white" />
        </div>

        <MediaPreview
          :show="showPreview"
          :media="{ url: post.thumbnail, name: '', is_video: false }"
          @close="showPreview = false"
        />
      </div>
    </div>

    <div class="shrink-0 w-36 flex items-center pe-base py-s">
      <span class="text-sm text-fg-neutral-primary whitespace-nowrap">{{ post.created_at }}</span>
    </div>

    <div v-if="showContentType" class="shrink-0 w-32 flex items-center pe-base py-s">
      <span class="text-sm text-fg-neutral-primary truncate">{{
        postTypeLabel(post.content_type)
      }}</span>
    </div>

    <div v-if="showPerformance" class="shrink-0 w-32 flex items-center pe-base py-s">
      <Badge
        v-if="performanceLabel"
        v-tooltip="$t('analytics.performance_vs_avg_desc')"
        :variant="performanceLabel.variant"
        class="text-xs cursor-default"
      >
        {{ performanceLabel.text }}
      </Badge>
    </div>

    <!-- Takes the slack, so the column rides the right edge of the row while its value still
         starts at the column's own left edge, under the title above it. -->
    <div
      v-if="primaryMetricKey && columns.length"
      class="ms-auto shrink-0 w-40 flex items-center pe-base py-s"
    >
      <span class="text-sm font-medium tabular-nums whitespace-nowrap" :class="{ 'text-fg-neutral-secondary': !hasPrimaryMetric }">
        {{ hasPrimaryMetric ? formatNumber(post.metrics[primaryMetricKey]) : 'Unavailable' }}
      </span>
    </div>

    <!-- Frozen too, so the menu stays reachable without scrolling the columns back. -->
    <div
      class="sticky end-0 z-10 shrink-0 w-14 flex items-center justify-end pe-base py-s transition-colors ease-in-out duration-200"
      :class="frozenBackground"
    >
      <!-- has-[.v-popper--shown] keeps the trigger up while its menu is open, even once the
           pointer has left the row. -->
      <div
        class="opacity-0 transition-opacity ease-in-out duration-200 group-hover/row:opacity-100 focus-within:opacity-100 has-[.v-popper--shown]:opacity-100 pointer-coarse:opacity-100"
        @click.stop
      >
        <AnalyticsPostActions :post="post" variant="dropdown" />
      </div>
    </div>
  </li>
</template>
