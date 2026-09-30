<script setup>
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import useMounted from '@/Composables/useMounted'
import { useAnalyticsNav } from '@/Composables/useAnalyticsNav'
import Pagination from '@/Components/Navigation/Pagination.vue'
import AnalyticsPostCard from '@/Components/Analytics/AnalyticsPostCard.vue'
import AnalyticsPostDetails from '@/Components/Analytics/AnalyticsPostDetails.vue'
import AnalyticsPostActions from '@/Components/Analytics/AnalyticsPostActions.vue'
import AnalyticsEmptyState from '@/Components/Analytics/AnalyticsEmptyState.vue'
import AnalyticsSortPicker from '@/Components/Analytics/AnalyticsSortPicker.vue'
import SlideOver from '@/Components/Modal/SlideOver.vue'

const props = defineProps({
  posts: {
    type: Array,
    default: () => []
  },
  pagination: {
    type: Object,
    default: () => ({ meta: {}, links: {} })
  },
  columns: {
    type: Array,
    required: true
  },
  primaryMetric: {
    type: String,
    default: null
  },
  avgMetrics: {
    type: Object,
    default: () => ({})
  }
})

const primaryMetricKey = computed(() => props.primaryMetric || props.columns[0]?.key || '')

const primaryColumn = computed(() => props.columns.find(c => c.key === primaryMetricKey.value))

// Use global average from backend (across all posts, not just current page)
const avgPrimaryMetric = computed(() => {
  const key = primaryMetricKey.value
  if (!key) return 0
  return props.avgMetrics[key] ?? 0
})

const hasPrimaryMetric = post => post.metrics?.[primaryMetricKey.value] != null

const performanceRatio = post => {
  const avg = avgPrimaryMetric.value
  if (avg === 0) return 1
  return (post.metrics?.[primaryMetricKey.value] ?? 0) / avg
}

const getPerformanceLabel = post => {
  if (!hasPrimaryMetric(post)) return null
  const ratio = performanceRatio(post)
  if (ratio >= 2) return { text: `${ratio.toFixed(1)}x avg`, variant: 'success' }
  if (ratio >= 1.2) return { text: 'Above avg', variant: 'success' }
  if (ratio >= 0.8) return { text: 'Average', variant: 'neutral' }
  return { text: 'Below avg', variant: 'warning' }
}

const getPerformanceBarWidth = post => {
  if (!hasPrimaryMetric(post)) return 0
  const ratio = performanceRatio(post)
  return Math.min(ratio * 50, 100)
}

const getPerformanceBarColor = post => {
  if (!hasPrimaryMetric(post)) return 'bg-fg-neutral-primary'
  const ratio = performanceRatio(post)
  if (ratio >= 1.2) return 'bg-fg-success'
  if (ratio >= 0.8) return 'bg-fg-neutral-primary'
  return 'bg-fg-warning'
}

const showPerformance = computed(() => (props.pagination?.meta?.total ?? 0) >= 3)

// Not every platform tells us what kind of post it is, and an empty column only eats the width
// the other ones need.
const showContentType = computed(() => props.posts.some(post => post.content_type))

const selectedPost = ref(null)

const showDetails = ref(false)

// The post outlives the panel on purpose: it keeps the details on screen while the panel
// slides back out.
const openPost = post => {
  selectedPost.value = post
  showDetails.value = true
}

const isSelected = post =>
  showDetails.value && selectedPost.value?.provider_post_id === post.provider_post_id

// The pinned head only casts a shadow once there are rows underneath it to cast it on.
const isListScrolled = ref(false)

const onListScroll = event => {
  isListScrolled.value = event.target.scrollTop > 0
}

const { isMounted } = useMounted()

const { changeSort, changeSortDir } = useAnalyticsNav()

const page = usePage()

const currentSortBy = computed(() => {
  const url = new URL(page.url, window.location.origin)
  return url.searchParams.get('sort_by') || ''
})

const currentSortDir = computed(() => {
  const url = new URL(page.url, window.location.origin)
  return url.searchParams.get('sort_dir') || 'desc'
})
</script>
<template>
  <!-- Bleeds out of the tab wrapper's padding: the table meets the nav row and the page edges
       edge to edge. -->
  <div v-if="posts?.length || pagination?.meta?.total > 0" class="-mt-base -mx-base -mb-base">
    <Teleport v-if="isMounted" to="#analyticsToolbar">
      <AnalyticsSortPicker
        :columns="columns"
        :sort-by="currentSortBy"
        :sort-dir="currentSortDir"
        @select-column="changeSort"
        @select-direction="changeSortDir"
      />
    </Teleport>

    <!-- Bounded to what is left of the viewport under the two nav rows, so the table owns the
         scroll on both axes: the head stays pinned and the horizontal scrollbar stays on screen
         instead of waiting at the far end of the list. -->
    <div
      class="max-h-[calc(100vh-var(--spacing-nav)*3)] overflow-auto bg-canvas"
      @scroll="onListScroll"
    >
      <!-- Every width here mirrors AnalyticsPostCard — that is what keeps the titles over
           their columns and the frozen cells over one another. -->
      <div
        class="sticky top-0 z-20 w-max min-w-full flex items-stretch bg-container-neutral-solid border-b border-border inset-shadow-highlight text-xs font-medium transition-shadow ease-in-out duration-200"
        :class="{ 'shadow-card': isListScrolled }"
      >
        <div
          class="sticky start-0 z-10 grow shrink-0 w-[17rem] sm:w-[22rem] lg:w-[28rem] max-w-[50%] flex items-center ps-base pe-base py-s bg-container-neutral-solid inset-shadow-highlight"
        >
          <span class="truncate">{{ $t('post.content') }}</span>
        </div>

        <div class="shrink-0 w-36 flex items-center pe-base py-s">
          <span class="truncate">{{ $t('general.date') }}</span>
        </div>

        <div v-if="showContentType" class="shrink-0 w-32 flex items-center pe-base py-s">
          <span class="truncate">{{ $t('general.type') }}</span>
        </div>

        <div v-if="showPerformance" class="shrink-0 w-32 flex items-center pe-base py-s">
          <span v-tooltip="$t('analytics.performance_vs_avg_desc')" class="truncate cursor-default">
            {{ $t('analytics.performance') }}
          </span>
        </div>

        <div
          v-if="primaryMetricKey && columns.length"
          class="ms-auto shrink-0 w-40 flex items-center pe-base py-s"
        >
          <span class="truncate">{{ primaryColumn ? $t(primaryColumn.label) : '' }}</span>
        </div>

        <div
          class="sticky end-0 z-10 shrink-0 w-14 pe-base py-s bg-container-neutral-solid inset-shadow-highlight"
        />
      </div>

      <ul role="list" class="divide-y divide-border">
        <AnalyticsPostCard
          v-for="post in posts"
          :key="post.provider_post_id"
          :post="post"
          :columns="columns"
          :primary-metric="primaryMetric"
          :show-content-type="showContentType"
          :show-performance="showPerformance"
          :performance-label="getPerformanceLabel(post)"
          :selected="isSelected(post)"
          @open="openPost(post)"
        />
      </ul>

      <!-- Paging belongs at the end of the page it pages, not pinned to the bottom of the
           screen. It only sticks to the start edge, so scrolling the columns sideways cannot
           carry it out of view, and a single page has nothing to page through at all. -->
      <div
        v-if="pagination?.meta?.last_page > 1"
        class="sticky start-0 w-full px-base h-nav flex items-center justify-center bg-container-neutral-solid border-t border-border inset-shadow-highlight"
      >
        <Pagination
          :meta="pagination.meta"
          :links="pagination.links"
          class="p-0! border-0! bg-transparent!"
        />
      </div>
    </div>

    <SlideOver max-width="xl" :show="showDetails" @close="showDetails = false">
      <template #header>{{ $t('analytics.post_details') }}</template>

      <template #body>
        <AnalyticsPostDetails
          v-if="selectedPost"
          :post="selectedPost"
          :columns="columns"
          :primary-metric="primaryMetric"
          :show-performance="showPerformance && hasPrimaryMetric(selectedPost)"
          :performance-label="getPerformanceLabel(selectedPost)"
          :performance-bar-width="getPerformanceBarWidth(selectedPost)"
          :performance-bar-color="getPerformanceBarColor(selectedPost)"
        />
      </template>

      <template #footer>
        <AnalyticsPostActions v-if="selectedPost" :post="selectedPost" />
      </template>
    </SlideOver>
  </div>

  <AnalyticsEmptyState v-else />
</template>
