<script setup>
import { computed, ref } from 'vue'
import FadedContainer from '@/Components/DataDisplay/FadedContainer.vue'
import MetaItem from '@/Components/DataDisplay/MetaItem.vue'
import MediaPreview from '@/Components/Media/MediaPreview.vue'
import PureButtonLink from '@/Components/Button/PureButtonLink.vue'
import Sparkline from '@/Components/Chart/Sparkline.vue'
import AnalyticsMetric from '@/Components/Analytics/AnalyticsMetric.vue'
import usePostTypeLabels from '@/Composables/usePostTypeLabels'
import ArrowTopRightOnSquare from '@/Icons/ArrowTopRightOnSquareMini.vue'
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
  performanceBarWidth: {
    type: Number,
    default: 0
  },
  performanceBarColor: {
    type: String,
    default: 'bg-black'
  },
  showPerformance: {
    type: Boolean,
    default: false
  }
})

const { postTypeLabel } = usePostTypeLabels()

const showPreview = ref(false)

const isEmpty = computed(() => !props.post.title?.trim() && !props.post.text?.trim())
</script>
<template>
  <div class="space-y-s">
    <FadedContainer>
      <template #title>{{ $t('general.meta') }}</template>

      <div class="grid grid-cols-3 gap-s">
        <MetaItem :label="$t('general.date')">{{ post.created_at }}</MetaItem>

        <MetaItem v-if="post.content_type" :label="$t('general.type')">
          {{ postTypeLabel(post.content_type) }}
        </MetaItem>

        <MetaItem
          v-if="showPerformance && performanceLabel"
          :label="$t('analytics.performance_vs_avg')"
          :tooltip="$t('analytics.performance_vs_avg_desc')"
        >
          {{ performanceLabel.text }}
        </MetaItem>
      </div>
    </FadedContainer>

    <FadedContainer>
      <template #title>
        <span class="block min-w-0 truncate">{{ post.title || $t('post.content') }}</span>
      </template>

      <div
        v-if="post.thumbnail"
        class="group relative cursor-pointer"
        :class="{ 'mb-s': post.text || isEmpty || post.link }"
        @click="showPreview = true"
      >
        <img
          :src="post.thumbnail"
          :alt="post.text"
          loading="lazy"
          class="w-full rounded-default object-cover object-center bg-surface"
        />

        <span
          class="absolute inset-0 flex items-center justify-center rounded-default bg-black/50 opacity-0 transition-opacity duration-200 group-hover:opacity-100"
        >
          <MagnifyingGlassIcon class="w-6! h-6! text-white" />
        </span>

        <MediaPreview
          :show="showPreview"
          :media="{ url: post.thumbnail, name: '', is_video: false }"
          @close="showPreview = false"
        />
      </div>

      <p v-if="post.text" class="text-sm text-fg-neutral-primary whitespace-pre-wrap break-words">
        {{ post.text }}
      </p>

      <p v-else-if="isEmpty" class="text-sm italic text-fg-neutral-secondary">
        {{ $t('post.empty_post') }}
      </p>

      <PureButtonLink
        v-if="post.link"
        :href="post.link"
        target="_blank"
        native
        class="max-w-full"
        :class="{ 'mt-s': post.text || isEmpty }"
      >
        <template #icon>
          <ArrowTopRightOnSquare />
        </template>

        <span class="text-sm break-all">{{ post.link }}</span>
      </PureButtonLink>
    </FadedContainer>

    <FadedContainer v-if="showPerformance && performanceBarWidth">
      <template #title>
        <span v-tooltip="$t('analytics.performance_vs_avg_desc')" class="cursor-default">
          {{ $t('analytics.performance_vs_avg') }}
        </span>
      </template>

      <div class="flex items-center gap-s">
        <div class="h-xs flex-1 rounded-default bg-container-neutral-stronger">
          <div
            class="h-full rounded-full transition-all"
            :class="performanceBarColor"
            :style="{ width: performanceBarWidth + '%' }"
          />
        </div>

        <span class="text-xs tabular-nums text-fg-neutral-primary"
          >{{ Math.round(performanceBarWidth) }}%</span
        >
      </div>
    </FadedContainer>

    <FadedContainer v-if="columns.length">
      <div class="grid grid-cols-2 gap-s">
        <AnalyticsMetric
          v-for="col in columns"
          :key="col.key"
          :label="$t(col.label)"
          :value="post.metrics?.[col.key] ?? 'Unavailable'"
          :formatted="post.metrics?.[col.key] == null"
          minified
        >
          <Sparkline
            v-if="post.trends?.[col.key]?.values?.length >= 2"
            :values="post.trends[col.key].values"
            :labels="post.trends[col.key].labels"
            :height="32"
          />
        </AnalyticsMetric>
      </div>
    </FadedContainer>
  </div>
</template>
