<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">监考复核详情</h1>
        <p v-if="data" class="text-sm text-gray-500 mt-1">
          {{ data.exam_paper?.title }} · 考生：{{ data.student?.real_name || data.student?.username }}
        </p>
      </div>
      <router-link to="/proctoring/review" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm">返回列表</router-link>
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <template v-else-if="data">
      <TimelineView :data="data">
        <template #actions="{ item }">
          <!-- 复核操作区 -->
          <div class="flex flex-wrap items-center gap-2 w-full">
            <template v-if="item.appeal?.status === 'pending'">
              <span class="text-xs font-semibold text-amber-700 mr-1">该异常有待处理申诉：</span>
              <button class="text-xs px-3 py-1.5 rounded-lg bg-green-600 text-white hover:bg-green-700 font-medium"
                @click="openAppealReview(item, 'approved')">
                申诉成立（撤销异常/恢复分数）
              </button>
              <button class="text-xs px-3 py-1.5 rounded-lg bg-red-600 text-white hover:bg-red-700 font-medium"
                @click="openAppealReview(item, 'rejected')">
                驳回申诉（维持判罚）
              </button>
            </template>
            <template v-else>
              <button v-if="item.status !== 'confirmed' && item.severity !== 'info'"
                class="text-xs px-3 py-1.5 rounded-lg bg-red-600 text-white hover:bg-red-700 font-medium"
                @click="openEventReview(item, 'confirm')">
                确认违规{{ item.status === 'pending' ? '并扣分' : '' }}
              </button>
              <button v-if="item.status !== 'dismissed'"
                class="text-xs px-3 py-1.5 rounded-lg bg-gray-500 text-white hover:bg-gray-600 font-medium"
                @click="openEventReview(item, 'dismiss')">
                撤销异常{{ item.status === 'confirmed' ? '并恢复分数' : '' }}
              </button>
              <span v-if="item.status === 'confirmed' && !item.appeal" class="text-xs text-red-500">已扣分</span>
              <span v-if="item.status === 'dismissed' && !item.appeal" class="text-xs text-gray-400">已撤销</span>
            </template>
          </div>
        </template>
      </TimelineView>

      <!-- 答卷得分构成 -->
      <div v-if="data.record?.answers?.length" class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
          <span class="w-1.5 h-6 bg-indigo-500 rounded-full mr-3"></span>作答与原始评分
        </h3>
        <table class="min-w-full text-sm">
          <thead class="text-gray-500">
            <tr>
              <th class="text-left py-2">题目</th>
              <th class="text-left py-2 w-24">学生答案</th>
              <th class="text-left py-2 w-20">判定</th>
              <th class="text-left py-2 w-20">得分</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(a, i) in data.record.answers" :key="i">
              <td class="py-2 pr-4 text-gray-700">{{ a.title }}</td>
              <td class="py-2">{{ a.answer }}</td>
              <td class="py-2">
                <span :class="a.is_correct ? 'text-green-600' : 'text-red-600'" class="font-medium">
                  {{ a.is_correct ? '正确' : '错误' }}
                </span>
              </td>
              <td class="py-2">{{ a.score }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 手动改分 -->
      <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
          <span class="w-1.5 h-6 bg-indigo-500 rounded-full mr-3"></span>手动调整成绩
        </h3>
        <p class="text-xs text-gray-500 mb-4">
          修改「原始得分基准」后，系统按 当前已确认违规扣分 {{ data.exam_record.deduction }} 分自动重算最终分；
          分数、异常标记与成绩统计将同步更新。
        </p>
        <div class="flex flex-wrap items-end gap-3">
          <div>
            <label class="block text-xs text-gray-500 mb-1">原始得分基准</label>
            <input v-model.number="baseScore" type="number" min="0" step="0.5"
              class="w-40 border border-gray-300 rounded-lg px-3 py-2 text-sm">
          </div>
          <div class="text-sm text-gray-600 pb-2">
            预计最终分：
            <b class="text-indigo-600 text-base">{{ previewFinal }}</b>
          </div>
          <div class="flex-1 min-w-[240px]">
            <label class="block text-xs text-gray-500 mb-1">改判说明 <span class="text-red-500">*</span></label>
            <input v-model="adjustNote" type="text" maxlength="500"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="如：第 3 题题意歧义，按作答酌情给分">
          </div>
          <button :disabled="adjusting || !adjustNote.trim() || baseScore === null"
            class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold disabled:opacity-50"
            @click="submitAdjust">
            {{ adjusting ? '提交中…' : '应用改判' }}
          </button>
        </div>
      </div>
    </template>

    <!-- 复核弹窗 -->
    <Teleport to="body">
      <div v-if="dialog" class="fixed inset-0 z-[95] bg-gray-600/75 flex items-center justify-center p-4" @click.self="dialog = null">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
          <h3 class="text-lg font-bold text-gray-900 mb-1">{{ dialog.title }}</h3>
          <p class="text-sm text-gray-500 mb-4">{{ dialog.event.type_label }} · {{ formatDateTime(dialog.event.occurred_at) }}</p>

          <template v-if="dialog.kind === 'event' && dialog.action === 'confirm'">
            <label class="block text-sm font-medium text-gray-700 mb-1">扣分（分）</label>
            <input v-model.number="penalty" type="number" min="0" step="0.5"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-3">
          </template>

          <label class="block text-sm font-medium text-gray-700 mb-1">复核意见 <span class="text-red-500">*</span></label>
          <textarea v-model="note" rows="3" maxlength="500"
            class="w-full border border-gray-300 rounded-lg p-3 text-sm"
            :placeholder="dialog.kind === 'appeal' ? '请填写复核意见（将展示给学生）' : '可填写复核备注'"></textarea>

          <div class="mt-6 flex justify-end gap-3">
            <button class="px-4 py-2 rounded-lg bg-white ring-1 ring-gray-300 text-sm font-semibold" @click="dialog = null">取消</button>
            <button :disabled="working || (dialog.kind === 'appeal' && note.trim().length < 2)"
              class="px-4 py-2 rounded-lg text-white text-sm font-semibold disabled:opacity-50"
              :class="dialog.confirmClass" @click="submitDialog">
              {{ working ? '处理中…' : dialog.confirmText }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../api'
import TimelineView from '../../components/proctoring/TimelineView.vue'
import { formatDateTime } from '../../utils/proctoring'
import { useToast } from '../../composables/useToast'

const route = useRoute()
const { success: toastSuccess, error: toastError } = useToast()

const data = ref(null)
const loading = ref(true)

const dialog = ref(null)
const note = ref('')
const penalty = ref(0)
const working = ref(false)

const baseScore = ref(null)
const adjustNote = ref('')
const adjusting = ref(false)

const previewFinal = computed(() => {
  if (baseScore.value === null || baseScore.value === undefined || !data.value) return '-'
  const deduction = Number(data.value.exam_record.deduction) || 0
  return Math.max(0, Number(baseScore.value) - deduction).toFixed(2)
})

const fetchData = async () => {
  loading.value = true
  try {
    const res = await api.get(`/proctoring/admin/records/${route.params.id}/timeline`)
    data.value = res.data
    baseScore.value = Number(res.data.exam_record.base_score)
  } finally {
    loading.value = false
  }
}

onMounted(fetchData)

const openEventReview = (item, action) => {
  dialog.value = {
    kind: 'event',
    action,
    event: item,
    title: action === 'confirm' ? '确认违规并扣分' : '撤销异常并恢复分数',
    confirmText: action === 'confirm' ? '确认违规' : '撤销异常',
    confirmClass: action === 'confirm' ? 'bg-red-600 hover:bg-red-500' : 'bg-gray-600 hover:bg-gray-500'
  }
  note.value = item.review_note || ''
  penalty.value = Number(item.penalty) || 0
}

const openAppealReview = (item, action) => {
  dialog.value = {
    kind: 'appeal',
    action,
    event: item,
    appealId: item.appeal.id,
    title: action === 'approved' ? '申诉成立：撤销异常、恢复分数' : '驳回申诉：维持违规判罚',
    confirmText: action === 'approved' ? '申诉成立' : '驳回申诉',
    confirmClass: action === 'approved' ? 'bg-green-600 hover:bg-green-500' : 'bg-red-600 hover:bg-red-500'
  }
  note.value = ''
  penalty.value = 0
}

const submitDialog = async () => {
  working.value = true
  try {
    if (dialog.value.kind === 'event') {
      const payload = { action: dialog.value.action, review_note: note.value.trim() || null }
      if (dialog.value.action === 'confirm') payload.penalty = Number(penalty.value) || 0
      await api.post(`/proctoring/events/${dialog.value.event.id}/review`, payload)
      toastSuccess(dialog.value.action === 'confirm' ? '已确认违规，成绩与统计已更新' : '已撤销异常，成绩已恢复')
    } else {
      await api.post(`/proctoring/appeals/${dialog.value.appealId}/review`, {
        action: dialog.value.action,
        review_note: note.value.trim()
      })
      toastSuccess('申诉复核完成，成绩与统计已更新')
    }
    dialog.value = null
    await fetchData()
  } catch (e) {
    toastError(e.response?.data?.message || '操作失败')
  } finally {
    working.value = false
  }
}

const submitAdjust = async () => {
  adjusting.value = true
  try {
    await api.post(`/proctoring/records/${route.params.id}/adjust-score`, {
      base_score: Number(baseScore.value),
      review_note: adjustNote.value.trim()
    })
    toastSuccess('改判成功，分数与统计已同步')
    adjustNote.value = ''
    await fetchData()
  } catch (e) {
    toastError(e.response?.data?.message || '改判失败')
  } finally {
    adjusting.value = false
  }
}
</script>
