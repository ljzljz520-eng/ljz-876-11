<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">我的申诉</h1>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <div v-else-if="appeals.length === 0" class="text-center py-12 text-gray-500 bg-white rounded-lg shadow">
      暂无申诉记录。考试结束后可在「我的成绩 → 监考回放」中对异常事件提交说明。
    </div>

    <div v-else class="space-y-4">
      <div v-for="appeal in appeals" :key="appeal.id" class="bg-white rounded-lg shadow p-5">
        <div class="flex flex-wrap items-center gap-3 mb-2">
          <span class="font-semibold text-gray-900">{{ appeal.exam_record?.exam_paper?.title }}</span>
          <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">
            {{ eventLabel(appeal.proctoring_event?.event_type) }}
          </span>
          <span class="px-2 py-0.5 rounded text-xs font-medium" :class="APPEAL_STATUS_STYLE[appeal.status].cls">
            {{ APPEAL_STATUS_STYLE[appeal.status].label }}
          </span>
          <span v-if="appeal.proctoring_event?.status === 'confirmed'" class="px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">违规成立</span>
          <span v-else-if="appeal.proctoring_event?.status === 'dismissed'" class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">异常已撤销</span>
          <span class="text-xs text-gray-400 ml-auto">{{ formatDateTime(appeal.created_at) }}</span>
        </div>

        <p class="text-sm text-gray-700 mb-2"><span class="text-gray-400">我的说明：</span>{{ appeal.reason }}</p>
        <img v-if="appeal.evidence_screenshot" :src="appeal.evidence_screenshot" class="max-h-40 rounded border mb-2">

        <div v-if="appeal.review_note" class="text-sm bg-indigo-50 text-indigo-800 rounded p-3">
          <div class="font-medium mb-1">教师复核意见（{{ formatDateTime(appeal.reviewed_at) }}）</div>
          {{ appeal.review_note }}
        </div>

        <div class="mt-3 text-xs text-gray-500">
          复核后成绩：
          <b class="text-indigo-600">{{ appeal.exam_record?.score }}</b> 分
          （原始 {{ appeal.exam_record?.base_score }} / 扣分 {{ appeal.exam_record?.deduction }} / 违规 {{ appeal.exam_record?.anomaly_count }} 条）
        </div>

        <router-link :to="`/proctoring/records/${appeal.exam_record_id}`"
          class="inline-block mt-2 text-xs text-indigo-600 hover:text-indigo-800 font-medium">
          查看监考回放 →
        </router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'
import { APPEAL_STATUS_STYLE, formatDateTime, EVENT_TYPES } from '../../utils/proctoring'

const appeals = ref([])
const loading = ref(true)

const eventLabel = (type) => EVENT_TYPES[type]?.label || type || '监考异常'

onMounted(async () => {
  try {
    const res = await api.get('/proctoring/appeals/mine')
    appeals.value = res.data.appeals.data
  } finally {
    loading.value = false
  }
})
</script>
