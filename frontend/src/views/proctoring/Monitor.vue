<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">监考回放</h1>
        <p class="text-sm text-gray-500 mt-1" v-if="record">
          {{ record.exam_paper?.title }} · 考试时间 {{ formatDateTime(record.start_time) }}
        </p>
      </div>
      <router-link to="/records" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">← 返回我的成绩</router-link>
    </div>

    <div v-if="loading" class="text-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <template v-else-if="record">
      <!-- 概览卡片 -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
          <div class="text-xs text-gray-500 mb-1">最终得分</div>
          <div class="text-2xl font-bold text-indigo-600">{{ Number(record.score).toFixed(2) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
          <div class="text-xs text-gray-500 mb-1">有效异常标记</div>
          <div class="text-2xl font-bold" :class="summary.active_anomaly_count > 0 ? 'text-red-600' : 'text-green-600'">
            {{ summary.active_anomaly_count }}
          </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
          <div class="text-xs text-gray-500 mb-1">已撤销异常</div>
          <div class="text-2xl font-bold text-gray-400">{{ summary.waived_anomaly_count }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
          <div class="text-xs text-gray-500 mb-1">申诉(待/成/驳)</div>
          <div class="text-2xl font-bold text-gray-800">
            {{ summary.appeal_pending }}/{{ summary.appeal_approved }}/{{ summary.appeal_rejected }}
          </div>
        </div>
      </div>

      <!-- 时间线 -->
      <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-5 flex items-center">
          <span class="w-1.5 h-6 bg-indigo-500 rounded-full mr-3"></span>
          事件时间线（按发生时间排序）
        </h3>

        <div v-if="timeline.length === 0" class="text-center text-gray-400 py-8">本场考试未采集到监考事件</div>

        <ol v-else class="relative border-l-2 border-gray-100 ml-3 space-y-6">
          <li v-for="item in timeline" :key="item.kind + '-' + (item.id || item.appeal?.id)" class="ml-6">
            <span
              class="absolute -left-[11px] flex items-center justify-center w-5 h-5 rounded-full ring-4 ring-white"
              :class="dotClass(item)"
            >
              <span class="w-2 h-2 rounded-full bg-white"></span>
            </span>

            <!-- 监考事件 -->
            <template v-if="item.kind === 'event'">
              <div class="rounded-lg border p-4" :class="item.is_waived ? 'bg-gray-50 border-gray-200' : eventCardClass(item)">
                <div class="flex items-start justify-between flex-wrap gap-2">
                  <div>
                    <div class="flex items-center gap-2 flex-wrap">
                      <span class="text-sm font-semibold" :class="item.is_anomaly && !item.is_waived ? 'text-red-700' : 'text-gray-700'">
                        {{ item.type_label }}
                      </span>
                      <span class="text-xs px-2 py-0.5 rounded-full" :class="badgeClass(item)">
                        {{ item.is_anomaly ? (item.is_waived ? '异常已撤销' : '异常') : '信息' }}
                      </span>
                    </div>
                    <p class="text-sm text-gray-500 mt-1">{{ item.detail || '—' }}</p>
                  </div>
                  <div class="text-right text-xs text-gray-400 whitespace-nowrap">
                    <div class="font-mono">{{ formatDateTime(item.occurred_at) }}</div>
                    <div>开考 +{{ formatOffset(item.offset_seconds) }}</div>
                  </div>
                </div>

                <!-- 该事件下的申诉 -->
                <div v-if="item.appeals && item.appeals.length" class="mt-3 space-y-2">
                  <div
                    v-for="appeal in item.appeals"
                    :key="appeal.id"
                    class="rounded-md bg-white/80 border border-gray-200 p-3 text-sm"
                  >
                    <div class="flex items-center justify-between">
                      <span class="font-medium" :class="appealStatusText(appeal.status).cls">{{ appealStatusText(appeal.status).text }}</span>
                      <span class="text-xs text-gray-400">{{ formatDateTime(appeal.created_at) }} 提交</span>
                    </div>
                    <p class="text-gray-600 mt-1">申诉说明：{{ appeal.reason }}</p>
                    <div v-if="appeal.review_comment" class="text-gray-500 mt-1">
                      复核意见：{{ appeal.review_comment }}
                      <span v-if="appeal.reviewer">（{{ appeal.reviewer.real_name || appeal.reviewer.username }}）</span>
                    </div>
                    <div v-if="appeal.status === 'approved'" class="mt-1 text-green-700">
                      改判分数：{{ Number(appeal.score_before).toFixed(2) }}
                      → <b>{{ Number(appeal.score_after).toFixed(2) }}</b>
                    </div>
                    <div v-if="appeal.evidence && appeal.evidence.length" class="mt-2 flex gap-2 flex-wrap">
                      <a
                        v-for="ev in appeal.evidence"
                        :key="ev.id"
                        :href="ev.url"
                        target="_blank"
                        class="inline-flex items-center text-xs text-indigo-600 hover:underline"
                      >
                        <img :src="ev.url" class="w-16 h-10 object-cover rounded border" alt="申诉截图">
                        查看截图
                      </a>
                    </div>
                  </div>
                </div>

                <!-- 申诉按钮 -->
                <div class="mt-3">
                  <button
                    v-if="canAppealFor(item)"
                    @click="openAppealModal(item)"
                    class="text-sm px-3 py-1.5 rounded-md bg-red-50 text-red-600 hover:bg-red-100 font-medium"
                  >
                    对该异常提交申诉
                  </button>
                </div>
              </div>
            </template>

            <!-- 不针对具体事件的整体申诉 -->
            <template v-else>
              <div class="rounded-lg border border-indigo-100 bg-indigo-50/60 p-4 text-sm">
                <span class="font-semibold text-indigo-700">整体申诉</span>
                <p class="text-gray-600 mt-1">{{ item.appeal.reason }}</p>
                <span class="text-xs px-2 py-0.5 rounded-full mt-2 inline-block" :class="appealStatusText(item.appeal.status).cls">
                  {{ appealStatusText(item.appeal.status).text }}
                </span>
              </div>
            </template>
          </li>
        </ol>
      </div>

      <!-- 对整场考试申诉（非具体异常） -->
      <div v-if="record.status === 'graded'" class="text-right">
        <button
          @click="openAppealModal(null)"
          class="text-sm px-4 py-2 rounded-md border border-indigo-300 text-indigo-600 hover:bg-indigo-50"
        >
          对整场考试提交说明/申诉
        </button>
      </div>
    </template>

    <!-- 申诉弹窗 -->
    <Teleport to="body">
      <div v-if="appealModal.show" class="fixed inset-0 z-[120] flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-gray-600/60" @click="closeAppealModal"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6">
          <h3 class="text-lg font-bold text-gray-900 mb-1">提交异常申诉</h3>
          <p class="text-sm text-gray-500 mb-4">
            {{ appealModal.event ? `针对事件：${appealModal.event.type_label}` : '针对整场考试' }}
          </p>

          <label class="block text-sm font-medium text-gray-700 mb-1">情况说明 <span class="text-red-500">*</span></label>
          <textarea
            v-model="appealModal.reason"
            rows="4"
            maxlength="1000"
            class="w-full border border-gray-300 rounded-lg p-3 text-sm focus:ring-indigo-500 focus:border-indigo-500"
            placeholder="请说明异常发生的客观原因（至少 5 个字）"
          ></textarea>

          <label class="block text-sm font-medium text-gray-700 mt-4 mb-1">截图凭证 <span class="text-red-500">*</span>（1-3 张，单张 ≤5MB，JPG/PNG/WebP）</label>
          <input
            ref="fileInput"
            type="file"
            accept="image/png,image/jpeg,image/webp"
            multiple
            class="block w-full text-sm text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-indigo-50 file:text-indigo-600 hover:file:bg-indigo-100"
            @change="onFilesChange"
          >
          <div v-if="appealModal.previews.length" class="flex gap-2 mt-3 flex-wrap">
            <img v-for="(src, i) in appealModal.previews" :key="i" :src="src" class="w-20 h-14 object-cover rounded border">
          </div>

          <div v-if="appealError" class="text-sm text-red-600 mt-3">{{ appealError }}</div>

          <div class="mt-6 flex justify-end gap-3">
            <button class="px-4 py-2 rounded-lg bg-white border border-gray-300 text-gray-700 hover:bg-gray-50" @click="closeAppealModal">取消</button>
            <button
              class="px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50"
              :disabled="appealModal.submitting"
              @click="submitAppeal"
            >
              {{ appealModal.submitting ? '提交中...' : '提交申诉' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../api'
import { useToast } from '../../composables/useToast'

const route = useRoute()
const toast = useToast()

const loading = ref(true)
const record = ref(null)
const timeline = ref([])
const summary = reactive({
  active_anomaly_count: 0,
  waived_anomaly_count: 0,
  appeal_pending: 0,
  appeal_approved: 0,
  appeal_rejected: 0
})

const fileInput = ref(null)
const appealError = ref('')
const appealModal = reactive({
  show: false,
  event: null,
  reason: '',
  files: [],
  previews: [],
  submitting: false
})

const formatDateTime = (v) => v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '-'
const formatOffset = (s) => {
  if (s == null) return '-'
  const m = Math.floor(s / 60)
  const sec = s % 60
  return `${m}分${sec.toString().padStart(2, '0')}秒`
}

const dotClass = (item) => {
  if (item.kind === 'appeal') return 'bg-indigo-400'
  if (item.is_waived) return 'bg-gray-300'
  if (item.is_anomaly) return 'bg-red-500'
  return 'bg-green-400'
}

const eventCardClass = (item) => {
  if (!item.is_anomaly) return 'bg-green-50/60 border-green-100'
  return 'bg-red-50/60 border-red-100'
}

const badgeClass = (item) => {
  if (item.is_waived) return 'bg-gray-200 text-gray-600'
  if (item.is_anomaly) return 'bg-red-100 text-red-700'
  return 'bg-green-100 text-green-700'
}

const appealStatusText = (status) => {
  return {
    pending: { text: '待老师复核', cls: 'bg-yellow-100 text-yellow-700' },
    approved: { text: '申诉成立（已改判）', cls: 'bg-green-100 text-green-700' },
    rejected: { text: '申诉已驳回', cls: 'bg-gray-200 text-gray-600' }
  }[status] || { text: status, cls: 'bg-gray-100 text-gray-600' }
}

const canAppealFor = (item) => {
  if (item.kind !== 'event' || !item.is_anomaly || item.is_waived) return false
  if (!record.value || record.value.status !== 'graded') return false
  // 已有待复核或已成立的申诉时不可重复提交
  return !(item.appeals || []).some((a) => a.status === 'pending' || a.status === 'approved')
}

const openAppealModal = (eventItem) => {
  appealModal.show = true
  appealModal.event = eventItem
  appealModal.reason = ''
  appealModal.files = []
  appealModal.previews = []
  appealError.value = ''
}

const closeAppealModal = () => {
  appealModal.show = false
  appealModal.previews.forEach((u) => URL.revokeObjectURL(u))
}

const onFilesChange = (e) => {
  appealModal.files = Array.from(e.target.files || [])
  appealModal.previews.forEach((u) => URL.revokeObjectURL(u))
  appealModal.previews = appealModal.files.map((f) => URL.createObjectURL(f))
}

const submitAppeal = async () => {
  appealError.value = ''
  if (appealModal.reason.trim().length < 5) {
    appealError.value = '请填写至少 5 个字的情况说明'
    return
  }
  if (!appealModal.files.length) {
    appealError.value = '请至少上传 1 张截图凭证'
    return
  }
  if (appealModal.files.some((f) => f.size > 5 * 1024 * 1024)) {
    appealError.value = '存在超过 5MB 的截图'
    return
  }

  const form = new FormData()
  form.append('exam_record_id', route.params.id)
  if (appealModal.event) form.append('proctoring_event_id', appealModal.event.id)
  form.append('reason', appealModal.reason.trim())
  appealModal.files.forEach((f) => form.append('screenshots[]', f))

  appealModal.submitting = true
  try {
    await api.post('/appeals', form, { headers: { 'Content-Type': 'multipart/form-data' } })
    toast.success('申诉已提交，等待老师复核')
    closeAppealModal()
    await load()
  } catch (e) {
    appealError.value = e.response?.data?.message || '提交失败，请重试'
    const fieldErrors = e.response?.data?.errors
    if (fieldErrors) {
      appealError.value = Object.values(fieldErrors).flat().join('；')
    }
  } finally {
    appealModal.submitting = false
  }
}

const load = async () => {
  loading.value = true
  try {
    const { data } = await api.get(`/proctoring/records/${route.params.id}/timeline`)
    record.value = data.record
    timeline.value = data.timeline
    Object.assign(summary, data.summary)
  } catch (e) {
    toast.error('加载监考记录失败')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
