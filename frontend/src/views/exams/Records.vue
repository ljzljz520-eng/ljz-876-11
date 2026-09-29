<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-gray-900">我的成绩</h1>
      <router-link to="/my-appeals" class="text-sm text-indigo-600 hover:underline">查看我的申诉 →</router-link>
    </div>
    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>
    <div v-else-if="records.length === 0" class="text-center py-8 text-gray-500">
      暂无考试记录
    </div>
    <div v-else class="bg-white shadow overflow-hidden sm:rounded-lg">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">试卷</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">得分</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">监考异常</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">申诉状态</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">考试时间</th>
            <th class="px-6 py-3"></th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <tr v-for="record in records" :key="record.id">
            <td class="px-6 py-4 whitespace-nowrap">{{ record.exam_paper?.title }}</td>
            <td class="px-6 py-4 whitespace-nowrap font-bold" :class="{'text-green-600': record.score >= 60, 'text-red-600': record.score < 60}">{{ Number(record.score).toFixed(2) }} 分</td>
            <td class="px-6 py-4 whitespace-nowrap">
              <span v-if="record.active_anomaly_count > 0" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700">
                {{ record.active_anomaly_count }} 个异常
              </span>
              <span v-else-if="record.event_total > 0" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs bg-green-100 text-green-700">
                无异常
              </span>
              <span v-else class="text-xs text-gray-400">未采集</span>
              <span v-if="record.waived_anomaly_count > 0" class="ml-1 text-xs text-gray-400">
                （已撤销 {{ record.waived_anomaly_count }}）
              </span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-xs">
              <span v-if="record.appeal_status === 'pending'" class="px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700 font-medium">待复核</span>
              <span v-else-if="record.appeal_status === 'approved'" class="px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-medium">已改判</span>
              <span v-else-if="record.appeal_status === 'rejected'" class="px-2 py-0.5 rounded-full bg-gray-200 text-gray-600 font-medium">已驳回</span>
              <span v-else class="text-gray-300">—</span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ new Date(record.created_at).toLocaleString() }}</td>
            <td class="px-6 py-4 whitespace-nowrap text-right">
              <router-link
                :to="`/records/${record.id}/monitor`"
                class="text-indigo-600 hover:text-indigo-900 text-sm font-medium"
              >
                监考回放 / 申诉
              </router-link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'

const records = ref([])
const loading = ref(true)

onMounted(async () => {
  try {
    const response = await api.get('/exams/records')
    records.value = response.data.records.data
  } catch (e) {
    console.error('Failed to fetch records:', e)
  } finally {
    loading.value = false
  }
})
</script>
