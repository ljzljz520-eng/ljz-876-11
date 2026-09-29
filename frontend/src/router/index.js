import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('../views/auth/Login.vue'),
    meta: { requiresAuth: false }
  },
  {
    path: '/register',
    name: 'Register',
    component: () => import('../views/auth/Register.vue'),
    meta: { requiresAuth: false }
  },
  {
    path: '/',
    name: 'Home',
    component: () => import('../views/Home.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/exams',
    name: 'Exams',
    component: () => import('../views/exams/Index.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/exams/:id',
    name: 'TakeExam',
    component: () => import('../views/exams/Take.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/records',
    name: 'Records',
    component: () => import('../views/exams/Records.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/proctoring/records/:id',
    name: 'StudentTimeline',
    component: () => import('../views/proctoring/StudentTimeline.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/appeals',
    name: 'MyAppeals',
    component: () => import('../views/proctoring/MyAppeals.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/proctoring/review',
    name: 'ProctoringReview',
    component: () => import('../views/proctoring/ReviewList.vue'),
    meta: { requiresAuth: true, roles: ['admin', 'teacher'] }
  },
  {
    path: '/proctoring/review/:id',
    name: 'ProctoringReviewDetail',
    component: () => import('../views/proctoring/ReviewDetail.vue'),
    meta: { requiresAuth: true, roles: ['admin', 'teacher'] }
  },
  {
    path: '/questions',
    name: 'Questions',
    component: () => import('../views/questions/Index.vue'),
    meta: { requiresAuth: true, roles: ['admin', 'teacher'] }
  },
  {
    path: '/exam-papers',
    name: 'ExamPapers',
    component: () => import('../views/exam-papers/Index.vue'),
    meta: { requiresAuth: true, roles: ['admin', 'teacher'] }
  },
  {
    path: '/statistics',
    name: 'Statistics',
    component: () => import('../views/statistics/Index.vue'),
    meta: { requiresAuth: true, roles: ['admin', 'teacher'] }
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

router.beforeEach((to, from, next) => {
  const authStore = useAuthStore()

  if (to.meta.requiresAuth && !authStore.isLoggedIn) {
    next('/login')
    return
  }

  if (to.meta.roles && !to.meta.roles.includes(authStore.user?.role)) {
    next('/')
    return
  }

  if ((to.name === 'Login' || to.name === 'Register') && authStore.isLoggedIn) {
    next('/')
    return
  }

  next()
})

export default router
