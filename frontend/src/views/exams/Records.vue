<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-gray-900">我的成绩</h1>
      <router-link to="/appeals" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">
        我的申诉 →
      </router-link>
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
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">原始分 / 扣分</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">最终得分</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">监考异常</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">状态</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">考试时间</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">操作</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <tr v-for="record in records" :key="record.id">
            <td class="px-6 py-4 whitespace-nowrap">{{ record.exam_paper?.title }}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
              <span>{{ record.base_score ?? record.score }}</span>
              <span v-if="Number(record.deduction) > 0" class="text-red-600"> - {{ record.deduction }}</span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap font-bold" :class="{'text-green-600': record.score >= 60, 'text-red-600': record.score < 60}">{{ record.score }} 分</td>
            <td class="px-6 py-4 whitespace-nowrap">
              <span v-if="Number(record.anomaly_count) > 0" class="px-2 py-0.5 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                {{ record.anomaly_count }} 条违规
              </span>
              <span v-else class="text-xs text-gray-400">无</span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
              <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                {{ record.status === 'graded' ? '已评分' : record.status }}
              </span>
              <span v-if="record.review_status === 'reviewed'" class="ml-1 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-indigo-100 text-indigo-800" :title="record.review_note || ''">
                已改判
              </span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ new Date(record.created_at).toLocaleString() }}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm">
              <router-link v-if="record.status === 'graded'" :to="`/proctoring/records/${record.id}`" class="text-indigo-600 hover:text-indigo-900 font-medium">
                监考回放
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
