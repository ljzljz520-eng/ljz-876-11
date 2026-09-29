<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-gray-900">监考复核</h1>
      <div class="flex gap-2 text-sm">
        <button :class="filterClass(null)" @click="filter = null; fetch(1)">全部异常考试</button>
        <button :class="filterClass('pending')" @click="filter = 'pending'; fetch(1)">待处理申诉</button>
        <button :class="filterClass('approved')" @click="filter = 'approved'; fetch(1)">已成立</button>
        <button :class="filterClass('rejected')" @click="filter = 'rejected'; fetch(1)">已驳回</button>
      </div>
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <div v-else-if="records.length === 0" class="text-center py-12 text-gray-500 bg-white rounded-lg shadow">
      暂无需要复核的考试
    </div>

    <div v-else class="bg-white shadow rounded-lg overflow-hidden">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">考生</th>
            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">试卷</th>
            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">成绩(原始-扣分)</th>
            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">违规</th>
            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">待处理</th>
            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">改判状态</th>
            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">操作</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="r in records" :key="r.id" class="hover:bg-gray-50">
            <td class="px-5 py-3 text-sm">
              <div class="font-medium text-gray-900">{{ r.user?.real_name || r.user?.username }}</div>
              <div class="text-xs text-gray-400">{{ r.user?.username }}</div>
            </td>
            <td class="px-5 py-3 text-sm text-gray-700">{{ r.exam_paper?.title }}</td>
            <td class="px-5 py-3 text-sm">
              <span class="text-gray-500">{{ r.base_score }}</span>
              <span v-if="Number(r.deduction) > 0" class="text-red-600"> - {{ r.deduction }} </span>
              = <b class="text-indigo-600">{{ r.score }}</b>
            </td>
            <td class="px-5 py-3">
              <span class="px-2 py-0.5 rounded text-xs font-semibold"
                :class="Number(r.anomaly_count) > 0 ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-500'">
                {{ r.anomaly_count }} 条
              </span>
            </td>
            <td class="px-5 py-3 text-xs">
              <span v-if="r.pending_counts?.pending_appeals" class="inline-block mr-2 px-2 py-0.5 rounded bg-amber-100 text-amber-700 font-semibold">
                {{ r.pending_counts.pending_appeals }} 条申诉
              </span>
              <span v-if="r.pending_counts?.pending_events" class="inline-block px-2 py-0.5 rounded bg-orange-100 text-orange-700 font-semibold">
                {{ r.pending_counts.pending_events }} 条事件待核
              </span>
              <span v-if="!r.pending_counts?.pending_appeals && !r.pending_counts?.pending_events" class="text-gray-400">无</span>
            </td>
            <td class="px-5 py-3 text-xs">
              <span v-if="r.review_status === 'reviewed'" class="px-2 py-0.5 rounded bg-indigo-100 text-indigo-700 font-semibold">已改判</span>
              <span v-else class="text-gray-400">系统判定</span>
            </td>
            <td class="px-5 py-3">
              <router-link :to="`/proctoring/review/${r.id}`"
                class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">
                进入复核
              </router-link>
            </td>
          </tr>
        </tbody>
      </table>

      <div class="px-5 py-4 flex items-center justify-between text-sm text-gray-500">
        <span>共 {{ total }} 条</span>
        <div class="flex gap-2">
          <button class="px-3 py-1 rounded border disabled:opacity-40" :disabled="page <= 1" @click="fetch(page - 1)">上一页</button>
          <span class="px-3 py-1">第 {{ page }} 页</span>
          <button class="px-3 py-1 rounded border disabled:opacity-40" :disabled="records.length < perPage" @click="fetch(page + 1)">下一页</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'

const records = ref([])
const loading = ref(true)
const filter = ref(null)
const page = ref(1)
const total = ref(0)
const perPage = 15

const filterClass = (val) => [
  'px-3 py-1.5 rounded-lg font-medium border',
  filter.value === val ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50'
]

const fetch = async (p = 1) => {
  loading.value = true
  try {
    const params = { page: p, per_page: perPage }
    if (filter.value) params.appeal_status = filter.value
    const res = await api.get('/proctoring/records', { params })
    records.value = res.data.records.data
    total.value = res.data.records.total
    page.value = p
  } finally {
    loading.value = false
  }
}

onMounted(() => fetch(1))
</script>
