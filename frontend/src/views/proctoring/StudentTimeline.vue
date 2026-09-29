<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">监考回放</h1>
        <p v-if="data" class="text-sm text-gray-500 mt-1">
          {{ data.exam_paper?.title }}
          <span v-if="data.student"> · {{ data.student.real_name || data.student.username }}</span>
        </p>
      </div>
      <router-link to="/records" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm">返回成绩</router-link>
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <template v-else-if="data">
      <TimelineView :data="data">
        <template #actions="{ item }">
          <span v-if="item.status === 'dismissed'" class="text-xs text-gray-400">该异常已撤销，不扣分</span>

          <template v-else-if="item.appeal">
            <span v-if="item.appeal.status === 'pending'" class="text-xs text-amber-600 font-medium">申诉处理中，请等待教师复核</span>
            <span v-else class="text-xs text-gray-500">申诉已处理：{{ item.appeal.status_label }}</span>
          </template>

          <button v-else-if="item.severity !== 'info'"
            class="text-xs px-3 py-1.5 rounded-lg bg-amber-500 text-white hover:bg-amber-600 font-medium"
            @click="openAppeal(item)">
            提交申诉说明
          </button>
        </template>
      </TimelineView>
    </template>

    <Teleport to="body">
      <div v-if="appealTarget" class="fixed inset-0 z-[95] bg-gray-600/75 flex items-center justify-center p-4" @click.self="appealTarget = null">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6">
          <h3 class="text-lg font-bold text-gray-900 mb-1">对异常提交申诉</h3>
          <p class="text-sm text-gray-500 mb-4">异常：{{ appealTarget.type_label }} · {{ formatDateTime(appealTarget.occurred_at) }}</p>

          <label class="block text-sm font-medium text-gray-700 mb-1">情况说明 <span class="text-red-500">*</span></label>
          <textarea v-model="reason" rows="4" maxlength="1000"
            class="w-full border border-gray-300 rounded-lg p-3 text-sm focus:ring-indigo-500 focus:border-indigo-500"
            placeholder="请说明该异常发生的客观原因，例如：系统更新弹窗导致失焦、摄像头被其他程序占用、网络运营商临时故障等"></textarea>

          <label class="block text-sm font-medium text-gray-700 mt-4 mb-1">佐证截图（可选）</label>
          <input type="file" accept="image/png,image/jpeg,image/webp" class="text-sm" @change="onPickFile">
          <div v-if="screenshot" class="mt-3 relative inline-block">
            <img :src="screenshot" class="max-h-40 rounded border">
            <button class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 text-xs" @click="screenshot = ''">×</button>
          </div>

          <div class="mt-6 flex justify-end gap-3">
            <button class="px-4 py-2 rounded-lg bg-white ring-1 ring-gray-300 text-sm font-semibold" @click="appealTarget = null">取消</button>
            <button :disabled="submitting || reason.trim().length < 5"
              class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold disabled:opacity-50"
              @click="submitAppeal">
              {{ submitting ? '提交中…' : '提交申诉' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../api'
import TimelineView from '../../components/proctoring/TimelineView.vue'
import { formatDateTime } from '../../utils/proctoring'
import { useToast } from '../../composables/useToast'

const route = useRoute()
const { success: toastSuccess, error: toastError } = useToast()

const data = ref(null)
const loading = ref(true)
const appealTarget = ref(null)
const reason = ref('')
const screenshot = ref('')
const submitting = ref(false)

const fetchData = async () => {
  loading.value = true
  try {
    const res = await api.get(`/proctoring/records/${route.params.id}/timeline`)
    data.value = res.data
  } finally {
    loading.value = false
  }
}

onMounted(fetchData)

const openAppeal = (item) => {
  appealTarget.value = item
  reason.value = ''
  screenshot.value = ''
}

const onPickFile = (e) => {
  const file = e.target.files?.[0]
  if (!file) return
  if (file.size > 1800 * 1024) {
    toastError('图片需小于约 1.8MB')
    e.target.value = ''
    return
  }
  const reader = new FileReader()
  reader.onload = () => { screenshot.value = reader.result }
  reader.readAsDataURL(file)
}

const submitAppeal = async () => {
  submitting.value = true
  try {
    await api.post('/proctoring/appeals', {
      proctoring_event_id: appealTarget.value.id,
      reason: reason.value.trim(),
      evidence_screenshot: screenshot.value || null
    })
    appealTarget.value = null
    toastSuccess('申诉已提交，等待教师复核')
    await fetchData()
  } finally {
    submitting.value = false
  }
}
</script>
