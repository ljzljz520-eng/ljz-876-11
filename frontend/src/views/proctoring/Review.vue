<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">监考复核台</h1>

    <!-- 筛选 -->
    <div class="bg-white rounded-lg shadow p-4 flex flex-wrap gap-3 items-end">
      <div>
        <label class="block text-xs text-gray-500 mb-1">试卷</label>
        <select v-model="filters.exam_paper_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
          <option value="">全部试卷</option>
          <option v-for="p in papers" :key="p.id" :value="p.id">{{ p.title }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs text-gray-500 mb-1">学生</label>
        <input v-model="filters.keyword" placeholder="用户名/姓名" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
      </div>
      <label class="flex items-center text-sm text-gray-600 gap-2 pb-2">
        <input type="checkbox" v-model="filters.has_anomaly" class="rounded text-indigo-600"> 仅有异常
      </label>
      <label class="flex items-center text-sm text-gray-600 gap-2 pb-2">
        <input type="checkbox" v-model="filters.appealPending" class="rounded text-indigo-600"> 仅待处理申诉
      </label>
      <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700" @click="load(1)">查询</button>
      <button class="px-4 py-2 bg-white border border-gray-300 text-gray-600 text-sm rounded-lg hover:bg-gray-50" @click="resetFilters">重置</button>
    </div>

    <!-- 列表 -->
    <div class="bg-white shadow overflow-hidden rounded-lg">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">学生</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">试卷</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">得分</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">异常/已撤销</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">待复核申诉</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">交卷时间</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="r in records" :key="r.id" class="hover:bg-gray-50">
            <td class="px-4 py-3">
              <div class="font-medium text-gray-900">{{ r.user?.real_name || r.user?.username }}</div>
              <div class="text-xs text-gray-400">{{ r.user?.username }}</div>
            </td>
            <td class="px-4 py-3 text-sm text-gray-600">{{ r.exam_paper?.title }}</td>
            <td class="px-4 py-3 font-bold" :class="Number(r.score) >= 60 ? 'text-green-600' : 'text-red-600'">
              {{ Number(r.score).toFixed(2) }}
            </td>
            <td class="px-4 py-3">
              <span :class="r.active_anomaly_count > 0 ? 'text-red-600 font-semibold' : 'text-gray-400'">
                {{ r.active_anomaly_count }}
              </span>
              <span class="text-gray-300 mx-1">/</span>
              <span class="text-gray-400">{{ r.waived_anomaly_count }}</span>
              <div class="flex gap-1 mt-1 flex-wrap">
                <span v-for="(cnt, type) in r.anomaly_by_type" :key="type"
                  class="text-[10px] px-1.5 py-0.5 rounded bg-red-50 text-red-600">
                  {{ shortType(type) }}×{{ cnt }}
                </span>
              </div>
            </td>
            <td class="px-4 py-3">
              <span v-if="r.pending_appeals > 0" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-yellow-100 text-yellow-700 font-semibold">
                {{ r.pending_appeals }} 条待处理
              </span>
              <span v-else class="text-gray-300 text-xs">无</span>
            </td>
            <td class="px-4 py-3 text-xs text-gray-400">{{ formatDateTime(r.end_time || r.updated_at) }}</td>
            <td class="px-4 py-3 text-right">
              <button class="text-indigo-600 text-sm font-medium hover:underline" @click="openDetail(r.id)">
                回放/复核
              </button>
            </td>
          </tr>
          <tr v-if="records.length === 0">
            <td colspan="7" class="px-4 py-10 text-center text-gray-400">暂无监考记录</td>
          </tr>
        </tbody>
      </table>

      <div class="px-4 py-3 flex justify-between items-center text-sm text-gray-500">
        <span>共 {{ total }} 条</span>
        <div class="flex gap-2">
          <button class="px-3 py-1 border rounded disabled:opacity-40" :disabled="page <= 1" @click="load(page - 1)">上一页</button>
          <span class="px-2">{{ page }} / {{ lastPage }}</span>
          <button class="px-3 py-1 border rounded disabled:opacity-40" :disabled="page >= lastPage" @click="load(page + 1)">下一页</button>
        </div>
      </div>
    </div>

    <!-- 复核抽屉 -->
    <Teleport to="body">
      <div v-if="detail.show" class="fixed inset-0 z-[110]">
        <div class="absolute inset-0 bg-gray-600/50" @click="closeDetail"></div>
        <div class="absolute right-0 top-0 h-full w-full max-w-3xl bg-gray-50 shadow-2xl overflow-y-auto">
          <div class="sticky top-0 bg-white border-b px-6 py-4 flex items-center justify-between z-10">
            <div>
              <h3 class="text-lg font-bold text-gray-900">监考回放与复核</h3>
              <p class="text-xs text-gray-500" v-if="detail.record">
                {{ detail.record.user?.real_name || detail.record.user?.username }} · {{ detail.record.exam_paper?.title }}
              </p>
            </div>
            <button class="text-gray-400 hover:text-gray-700 text-2xl leading-none" @click="closeDetail">×</button>
          </div>

          <div v-if="detail.loading" class="text-center py-16">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
          </div>

          <div v-else-if="detail.record" class="p-6 space-y-6">
            <!-- 汇总 -->
            <div class="grid grid-cols-4 gap-3">
              <div class="bg-white rounded-lg p-3 text-center shadow-sm">
                <div class="text-xs text-gray-400">当前得分</div>
                <div class="text-xl font-bold text-indigo-600">{{ Number(detail.record.score).toFixed(2) }}</div>
              </div>
              <div class="bg-white rounded-lg p-3 text-center shadow-sm">
                <div class="text-xs text-gray-400">有效异常</div>
                <div class="text-xl font-bold text-red-600">{{ detail.summary.active_anomaly_count }}</div>
              </div>
              <div class="bg-white rounded-lg p-3 text-center shadow-sm">
                <div class="text-xs text-gray-400">已撤销</div>
                <div class="text-xl font-bold text-gray-400">{{ detail.summary.waived_anomaly_count }}</div>
              </div>
              <div class="bg-white rounded-lg p-3 text-center shadow-sm">
                <div class="text-xs text-gray-400">待申诉</div>
                <div class="text-xl font-bold text-yellow-600">{{ detail.summary.appeal_pending }}</div>
              </div>
            </div>

            <!-- 时间线 -->
            <div class="bg-white rounded-lg shadow-sm p-5">
              <h4 class="font-bold text-gray-800 mb-4">时间线</h4>
              <ol class="relative border-l-2 border-gray-100 ml-3 space-y-4">
                <li v-for="item in detail.timeline" :key="item.kind + '-' + (item.id || item.appeal?.id)" class="ml-5">
                  <span class="absolute -left-[9px] w-4 h-4 rounded-full ring-4 ring-white"
                    :class="item.kind === 'appeal' ? 'bg-indigo-400' : (item.is_waived ? 'bg-gray-300' : (item.is_anomaly ? 'bg-red-500' : 'bg-green-400'))"></span>
                  <div class="text-xs text-gray-400 font-mono">{{ formatDateTime(item.occurred_at || item.at) }}</div>
                  <div v-if="item.kind === 'event'" class="text-sm mt-0.5">
                    <b :class="item.is_anomaly && !item.is_waived ? 'text-red-600' : 'text-gray-700'">{{ item.type_label }}</b>
                    <span v-if="item.is_waived" class="ml-1 text-xs text-gray-400">（已因申诉撤销）</span>
                    <span v-else-if="!item.is_anomaly" class="ml-1 text-xs text-green-600">信息</span>
                    <div class="text-gray-500">{{ item.detail }}</div>
                  </div>
                  <div v-else class="text-sm text-indigo-700">学生整体申诉</div>
                </li>
              </ol>
            </div>

            <!-- 申诉复核 -->
            <div v-for="appeal in detail.appeals" :key="appeal.id" class="bg-white rounded-lg shadow-sm p-5 border"
              :class="appeal.status === 'pending' ? 'border-yellow-200' : 'border-gray-100'">
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                  <span class="font-bold text-gray-800">申诉 #{{ appeal.id }}</span>
                  <span class="text-xs px-2 py-0.5 rounded-full" :class="appealStatusClass(appeal.status)">{{ appealStatusText(appeal.status) }}</span>
                </div>
                <span class="text-xs text-gray-400">关联：{{ appeal.proctoring_event ? eventLabel(appeal.proctoring_event.type) : '整场考试' }}</span>
              </div>

              <p class="text-sm text-gray-700 mt-3 bg-gray-50 rounded-md p-3">{{ appeal.reason }}</p>

              <div class="mt-3 flex gap-3 flex-wrap">
                <a v-for="ev in appeal.evidence" :key="ev.id" :href="ev.url" target="_blank">
                  <img :src="ev.url" class="w-28 h-20 object-cover rounded border hover:opacity-80" alt="证据">
                </a>
              </div>

              <!-- 复核表单 -->
              <div v-if="appeal.status === 'pending'" class="mt-4 border-t pt-4 space-y-3">
                <div class="flex flex-wrap gap-4">
                  <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" v-model="reviewForms[appeal.id].waive" class="rounded text-indigo-600">
                    撤销该异常标记
                  </label>
                  <label class="flex items-center gap-2 text-sm">
                    分数调整：
                    <input
                      type="number"
                      step="0.5"
                      v-model.number="reviewForms[appeal.id].adjustment"
                      class="w-28 border border-gray-300 rounded px-2 py-1 text-sm"
                      placeholder="如 2 或 -1"
                    >
                    分（可加可减）
                  </label>
                </div>
                <textarea
                  v-model="reviewForms[appeal.id].comment"
                  rows="2"
                  maxlength="1000"
                  class="w-full border border-gray-300 rounded-lg p-2 text-sm"
                  placeholder="复核意见（可选）"
                ></textarea>
                <div class="flex gap-3 justify-end">
                  <button
                    class="px-4 py-2 rounded-lg bg-white border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm"
                    :disabled="reviewForms[appeal.id].submitting"
                    @click="submitReview(appeal, 'rejected')"
                  >驳回</button>
                  <button
                    class="px-4 py-2 rounded-lg bg-green-600 text-white hover:bg-green-700 text-sm disabled:opacity-50"
                    :disabled="reviewForms[appeal.id].submitting"
                    @click="submitReview(appeal, 'approved')"
                  >申诉成立并改判</button>
                </div>
              </div>

              <div v-else class="mt-3 border-t pt-3 text-sm text-gray-500">
                <div>复核人：{{ appeal.reviewer?.real_name || appeal.reviewer?.username || '—' }} · {{ formatDateTime(appeal.reviewed_at) }}</div>
                <div v-if="appeal.review_comment" class="mt-1">意见：{{ appeal.review_comment }}</div>
                <div v-if="appeal.status === 'approved'" class="mt-1 text-green-700">
                  改判：{{ Number(appeal.score_before).toFixed(2) }} → <b>{{ Number(appeal.score_after).toFixed(2) }}</b>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import api from '../../api'
import { useToast } from '../../composables/useToast'
import { useModal } from '../../composables/useModal'

const toast = useToast()
const { confirm } = useModal()

const records = ref([])
const papers = ref([])
const total = ref(0)
const lastPage = ref(1)
const page = ref(1)
const loading = ref(false)

const filters = reactive({
  exam_paper_id: '',
  keyword: '',
  has_anomaly: false,
  appealPending: false
})

const labels = {
  tab_switch: '切屏',
  camera_disconnected: '摄像头断开',
  idle_long: '长时间无操作',
  network_lost: '网络中断',
  network_restored: '网络恢复',
  camera_restored: '摄像头恢复',
  fullscreen_exit: '退出全屏',
  monitor_start: '开始监考'
}
const shortType = (t) => labels[t] || t
const eventLabel = (t) => labels[t] || t

const detail = reactive({
  show: false,
  loading: false,
  record: null,
  timeline: [],
  appeals: [],
  summary: {}
})
const reviewForms = reactive({})

const formatDateTime = (v) => v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '-'
const appealStatusText = (s) => ({ pending: '待复核', approved: '成立·已改判', rejected: '已驳回' }[s] || s)
const appealStatusClass = (s) => ({
  pending: 'bg-yellow-100 text-yellow-700',
  approved: 'bg-green-100 text-green-700',
  rejected: 'bg-gray-200 text-gray-600'
}[s] || 'bg-gray-100')

const load = async (p = 1) => {
  loading.value = true
  page.value = p
  try {
    const params = { page: p }
    if (filters.exam_paper_id) params.exam_paper_id = filters.exam_paper_id
    if (filters.keyword.trim()) params.keyword = filters.keyword.trim()
    if (filters.has_anomaly) params.has_anomaly = '1'
    if (filters.appealPending) params.appeal_status = 'pending'

    const { data } = await api.get('/proctoring/records', { params })
    records.value = data.records.data
    total.value = data.records.total
    lastPage.value = data.records.last_page
    papers.value = data.exam_papers
  } finally {
    loading.value = false
  }
}

const resetFilters = () => {
  filters.exam_paper_id = ''
  filters.keyword = ''
  filters.has_anomaly = false
  filters.appealPending = false
  load(1)
}

const openDetail = async (id) => {
  detail.show = true
  detail.loading = true
  detail.record = null
  detail.timeline = []
  detail.appeals = []
  try {
    const { data } = await api.get(`/proctoring/records/${id}/review`)
    detail.record = data.record
    detail.timeline = data.timeline
    detail.appeals = data.appeals
    detail.summary = data.summary
    data.appeals.forEach((a) => {
      if (a.status === 'pending' && !reviewForms[a.id]) {
        reviewForms[a.id] = { waive: true, adjustment: 0, comment: '', submitting: false }
      }
    })
  } finally {
    detail.loading = false
  }
}

const closeDetail = () => {
  detail.show = false
}

const submitReview = async (appeal, decision) => {
  const form = reviewForms[appeal.id]
  if (decision === 'approved') {
    const adj = Number(form.adjustment || 0)
    const tips = []
    if (form.waive) tips.push('撤销该异常标记')
    if (adj !== 0) tips.push(`${adj > 0 ? '加' : '减'} ${Math.abs(adj)} 分`)
    const ok = await confirm(
      `确认申诉成立？将执行：${tips.join('、') || '仅更新状态（不改分/不撤销标记）'}。成绩统计会实时联动更新。`,
      '复核确认',
      'warning'
    )
    if (!ok) return
  } else {
    const ok = await confirm('确认驳回该申诉？驳回后异常标记保留、分数不变。', '驳回确认', 'warning')
    if (!ok) return
  }

  form.submitting = true
  try {
    await api.post(`/appeals/${appeal.id}/review`, {
      decision,
      review_comment: form.comment,
      score_adjustment: Number(form.adjustment || 0),
      waive_event: form.waive
    })
    toast.success(decision === 'approved' ? '已改判，分数与异常标记已更新' : '申诉已驳回')
    await openDetail(appeal.exam_record_id)
    await load(page.value)
  } catch (e) {
    toast.error(e.response?.data?.message || '复核失败')
  } finally {
    form.submitting = false
  }
}

load(1)
</script>
