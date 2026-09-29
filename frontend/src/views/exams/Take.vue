<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">{{ examPaper?.title }}</h1>
      <div class="text-lg">
        剩余时间: <span class="font-mono font-bold" :class="{'text-red-600': timeRemaining < 60}">{{ formatTime(timeRemaining) }}</span>
      </div>
    </div>

    <!-- 监考状态条 -->
    <div class="bg-white rounded-lg shadow px-4 py-3 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
      <span class="flex items-center text-gray-600">
        <span class="w-2 h-2 rounded-full mr-2" :class="cameraState === 'on' ? 'bg-green-500' : 'bg-red-500 animate-pulse'"></span>
        摄像头：{{ cameraLabel }}
      </span>
      <span class="flex items-center text-gray-600">
        <span class="w-2 h-2 rounded-full mr-2" :class="networkState === 'online' ? 'bg-green-500' : 'bg-red-500 animate-pulse'"></span>
        网络：{{ networkState === 'online' ? '正常' : '已断开' }}
      </span>
      <span class="flex items-center" :class="activeAnomalyCount > 0 ? 'text-red-600 font-semibold' : 'text-gray-400'">
        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        本次异常：{{ activeAnomalyCount }} 次
      </span>
      <span class="text-gray-400 text-xs">切屏、摄像头中断、长时间无操作、网络变化均会按时间记录</span>
    </div>
    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>
    <div v-else-if="!loading && questions.length > 0" class="space-y-8">
      <div v-for="(question, index) in questions" :key="question.id" class="bg-white rounded-lg shadow p-6">
        <div class="flex items-start mb-4">
          <span class="bg-indigo-100 text-indigo-800 text-sm font-medium px-2.5 py-0.5 rounded mr-3">{{ index + 1 }}</span>
          <div class="flex-1">
            <h3 class="text-lg font-medium text-gray-900 mb-2">{{ question.title }}</h3>
            <p class="text-sm text-gray-500 mb-3">分值: {{ question.score }}分 | 题型: {{ questionTypeLabel(question.type) }}</p>
            <div class="space-y-2">
              <!-- 单选题 -->
              <template v-if="question.type === 'single_choice'">
                <label v-for="(label, key) in question.options" :key="key" class="flex items-center p-3 border rounded cursor-pointer hover:bg-gray-50" :class="{'border-indigo-500 bg-indigo-50': answers[question.id] === key}">
                  <input type="radio" :name="'question_' + question.id" :value="key" v-model="answers[question.id]" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                  <span class="ml-3">{{ key }}. {{ label }}</span>
                </label>
              </template>
              <!-- 判断题 -->
              <template v-else-if="question.type === 'true_false'">
                <label class="flex items-center p-3 border rounded cursor-pointer hover:bg-gray-50" :class="{'border-indigo-500 bg-indigo-50': answers[question.id] === 'true'}">
                  <input type="radio" :name="'question_' + question.id" value="true" v-model="answers[question.id]" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                  <span class="ml-3">正确</span>
                </label>
                <label class="flex items-center p-3 border rounded cursor-pointer hover:bg-gray-50" :class="{'border-indigo-500 bg-indigo-50': answers[question.id] === 'false'}">
                  <input type="radio" :name="'question_' + question.id" value="false" v-model="answers[question.id]" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                  <span class="ml-3">错误</span>
                </label>
              </template>
              <!-- 多选题 -->
              <template v-else-if="question.type === 'multiple_choice'">
                <label v-for="(label, key) in question.options" :key="key" class="flex items-center p-3 border rounded cursor-pointer hover:bg-gray-50" :class="{'border-indigo-500 bg-indigo-50': (answers[question.id] || []).includes(key)}">
                  <input type="checkbox" :value="key" @change="toggleMultipleChoice(question.id, key)" :checked="(answers[question.id] || []).includes(key)" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                  <span class="ml-3">{{ key }}. {{ label }}</span>
                </label>
              </template>
              <!-- 填空题/问答题 -->
              <template v-else>
                <textarea v-model="answers[question.id]" rows="3" class="w-full border border-gray-300 rounded-md p-3 focus:ring-indigo-500 focus:border-indigo-500" placeholder="请输入答案"></textarea>
              </template>
            </div>
          </div>
        </div>
      </div>
      <div class="flex justify-between">
        <router-link to="/exams" class="bg-gray-300 text-gray-700 py-2 px-4 rounded hover:bg-gray-400">返回</router-link>
        <button @click="submitExam" :disabled="submitting" class="bg-indigo-600 text-white py-2 px-6 rounded hover:bg-indigo-700 disabled:opacity-50">
          {{ submitting ? '提交中...' : '提交答卷' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../api'
import { useModal } from '../../composables/useModal'
import { useProctoring } from '../../composables/useProctoring'

const route = useRoute()
const router = useRouter()
const { alert } = useModal()
const examPaper = ref(null)
const examRecordId = ref(null)
const examRecord = ref(null)
const questions = ref([])
const answers = ref({})
const loading = ref(true)
const submitting = ref(false)
const timeRemaining = ref(0)
let timer = null

const {
  cameraState,
  networkState,
  activeAnomalyCount,
  start: startProctoring,
  stop: stopProctoring,
  flush: flushEvents
} = useProctoring(examRecordId)

const cameraLabel = computed(() => {
  return { on: '正常', off: '已断开', denied: '未授权', unknown: '检测中' }[cameraState.value] || '检测中'
})

onMounted(async () => {
  try {
    const response = await api.get(`/exams/${route.params.id}/questions`)
    examPaper.value = response.data.exam_paper
    examRecord.value = response.data.exam_record
    examRecordId.value = examRecord.value.id
    timeRemaining.value = examPaper.value.total_time * 60
    startTimer()
    await startProctoring()
  } catch (e) {
    alert('获取考试信息失败', '考试加载失败', 'error')
    router.push('/exams')
  } finally {
    loading.value = false
  }
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
  stopProctoring()
})

const startTimer = () => {
  timer = setInterval(() => {
    if (timeRemaining.value > 0) {
      timeRemaining.value--
    } else {
      clearInterval(timer)
      submitExam()
    }
  }, 1000)
}

const formatTime = (seconds) => {
  const mins = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
}

const questionTypeLabel = (type) => {
  const labels = {
    single_choice: '单选题',
    multiple_choice: '多选题',
    true_false: '判断题',
    fill_blank: '填空题',
    essay: '问答题'
  }
  return labels[type] || type
}

const toggleMultipleChoice = (questionId, key) => {
  if (!answers.value[questionId]) {
    answers.value[questionId] = []
  }
  const index = answers.value[questionId].indexOf(key)
  if (index === -1) {
    answers.value[questionId].push(key)
  } else {
    answers.value[questionId].splice(index, 1)
  }
}

const submitExam = async () => {
  if (submitting.value) return
  submitting.value = true
  try {
    // 交卷前先把监考事件全部上报
    await flushEvents()
    const answerData = Object.entries(answers.value).map(([questionId, answer]) => ({
      question_id: parseInt(questionId),
      answer: Array.isArray(answer) ? answer.join(',') : answer
    }))
    const response = await api.post(`/exams/${route.params.id}/submit`, {
      exam_record_id: examRecord.value.id,
      answers: answerData
    })
    stopProctoring()
    alert(`考试完成！得分: ${response.data.score}。可在"我的成绩"中查看监考回放并提交申诉。`, '考试完成', 'success')
    router.push('/records')
  } catch (e) {
    alert(e.response?.data?.message || '提交失败', '提交失败', 'error')
  } finally {
    submitting.value = false
  }
}
</script>
