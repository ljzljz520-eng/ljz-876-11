<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">我的申诉</h1>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <div v-else-if="appeals.length === 0" class="bg-white rounded-lg shadow p-12 text-center text-gray-400">
      暂无申诉记录。可在"我的成绩 → 监考回放"中对异常事件提交申诉。
    </div>

    <div v-else class="space-y-4">
      <div v-for="appeal in appeals" :key="appeal.id" class="bg-white rounded-lg shadow p-5">
        <div class="flex items-start justify-between flex-wrap gap-3">
          <div>
            <div class="font-semibold text-gray-900">{{ appeal.exam_paper?.title || `考试记录 #${appeal.exam_record_id}` }}</div>
            <div class="text-sm text-gray-500 mt-1">
              关联异常：{{ appeal.proctoring_event ? eventLabel(appeal.proctoring_event.type) : '整场考试' }}
            </div>
          </div>
          <span class="text-xs px-2.5 py-1 rounded-full font-medium" :class="statusClass(appeal.status)">
            {{ statusText(appeal.status) }}
          </span>
        </div>

        <p class="text-sm text-gray-600 mt-3 bg-gray-50 rounded-md p-3">{{ appeal.reason }}</p>

        <div v-if="appeal.evidence && appeal.evidence.length" class="mt-3 flex gap-3 flex-wrap">
          <a v-for="ev in appeal.evidence" :key="ev.id" :href="ev.url" target="_blank">
            <img :src="ev.url" class="w-24 h-16 object-cover rounded border hover:opacity-80" alt="证据截图">
          </a>
        </div>

        <div v-if="appeal.status !== 'pending'" class="mt-3 text-sm border-t pt-3">
          <div class="text-gray-500">
            复核人：{{ appeal.reviewer?.real_name || appeal.reviewer?.username || '—' }} ·
            时间：{{ formatDateTime(appeal.reviewed_at) }}
          </div>
          <div v-if="appeal.review_comment" class="text-gray-700 mt-1">复核意见：{{ appeal.review_comment }}</div>
          <div v-if="appeal.status === 'approved'" class="mt-1 text-green-700">
            分数调整：{{ Number(appeal.score_before).toFixed(2) }} → <b>{{ Number(appeal.score_after).toFixed(2) }}</b>
            （{{ Number(appeal.score_adjustment) >= 0 ? '+' : '' }}{{ Number(appeal.score_adjustment).toFixed(2) }} 分）
          </div>
        </div>

        <div class="text-xs text-gray-400 mt-2">提交于 {{ formatDateTime(appeal.created_at) }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'

const appeals = ref([])
const loading = ref(true)

const labels = {
  tab_switch: '切屏/离开考试窗口',
  camera_disconnected: '摄像头断开',
  idle_long: '长时间无操作',
  network_lost: '网络中断',
  fullscreen_exit: '退出全屏'
}
const eventLabel = (t) => labels[t] || t

const statusText = (s) => ({ pending: '待复核', approved: '申诉成立·已改判', rejected: '已驳回' }[s] || s)
const statusClass = (s) => ({
  pending: 'bg-yellow-100 text-yellow-700',
  approved: 'bg-green-100 text-green-700',
  rejected: 'bg-gray-200 text-gray-600'
}[s] || 'bg-gray-100 text-gray-600')

const formatDateTime = (v) => v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '-'

onMounted(async () => {
  try {
    const { data } = await api.get('/appeals/mine')
    appeals.value = data.appeals.data || []
  } finally {
    loading.value = false
  }
})
</script>
