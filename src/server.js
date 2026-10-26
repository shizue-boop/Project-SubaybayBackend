import 'dotenv/config'
import crypto from 'node:crypto'
import fs from 'node:fs/promises'
import path from 'node:path'
import { performance } from 'node:perf_hooks'
import { fileURLToPath } from 'node:url'
import bcrypt from 'bcryptjs'
import cors from 'cors'
import express from 'express'
import multer from 'multer'
import { insert, one, pool, query, transaction } from './db.js'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const app = express()
// Express 4 does not catch rejected async route handlers by itself.
for (const method of ['get', 'post', 'put', 'patch', 'delete', 'use']) {
  const register = app[method].bind(app)
  app[method] = (...args) => register(...args.map((value) => typeof value === 'function' && value.constructor?.name === 'AsyncFunction'
    ? (req, res, next) => Promise.resolve(value(req, res, next)).catch(next)
    : value))
}
const port = Number(process.env.PORT || 8000)
const storageRoot = path.resolve(process.env.LOCAL_STORAGE_PATH || path.join(__dirname, '..', 'storage'))
const uploadsRoot = path.join(storageRoot, 'uploads')
const origins = (process.env.FRONTEND_ORIGINS || process.env.FRONTEND_URL || 'http://localhost:5173')
  .split(',').map((origin) => origin.trim()).filter(Boolean)

app.disable('x-powered-by')
app.use(cors({ origin(origin, callback) {
  if (!origin || origins.includes('*') || origins.includes(origin)) return callback(null, true)
  return callback(new Error('Origin is not allowed by CORS.'))
}, allowedHeaders: ['Content-Type', 'Authorization', 'Accept'], methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], maxAge: 600 }))
app.use(express.json({ limit: '2mb' }))
app.use(express.urlencoded({ extended: true, limit: '2mb' }))
app.use((req, res, next) => {
  const startedAt = performance.now()
  res.on('finish', () => {
    const durationMs = Math.round(performance.now() - startedAt)
    if (durationMs >= 500 || res.statusCode >= 500) {
      console.warn(JSON.stringify({ event: 'api_request', method: req.method, path: req.path, status: res.statusCode, duration_ms: durationMs }))
    }
  })
  next()
})

const upload = multer({ storage: multer.memoryStorage(), limits: { fileSize: 20 * 1024 * 1024 } })
const imageUpload = multer({ storage: multer.memoryStorage(), limits: { fileSize: 5 * 1024 * 1024 } })
const nowSql = 'NOW()'
const adminRoles = ['principal', 'coordinator']
const safeUserFields = 'id, full_name, email, role, status, section, grade_level, profile_image, contact_number, joined_date'
const authCacheTtlMs = Math.max(0, Number(process.env.AUTH_CACHE_TTL_MS || 10000))
const maxCachedAuthUsers = 2000
const authUserCache = new Map()

function cachedAuthUser(tokenId, tokenHash) {
  const entry = authUserCache.get(String(tokenId))
  if (!entry || entry.tokenHash !== tokenHash || entry.expiresAt <= Date.now()) {
    if (entry) authUserCache.delete(String(tokenId))
    return null
  }
  // Refresh insertion order so the cache evicts least recently used entries.
  authUserCache.delete(String(tokenId))
  authUserCache.set(String(tokenId), entry)
  return entry.user
}
function rememberAuthUser(tokenId, tokenHash, user) {
  if (!authCacheTtlMs) return
  const key = String(tokenId)
  authUserCache.delete(key)
  authUserCache.set(key, { tokenHash, user, expiresAt: Date.now() + authCacheTtlMs })
  while (authUserCache.size > maxCachedAuthUsers) authUserCache.delete(authUserCache.keys().next().value)
}
function invalidateAuthUser(tokenId) { authUserCache.delete(String(tokenId)) }
function invalidateUserAuth(userId) {
  for (const [tokenId, entry] of authUserCache) {
    if (String(entry.user.id) === String(userId)) authUserCache.delete(tokenId)
  }
}

function fail(status, message, errors) {
  const error = new Error(message)
  error.status = status
  if (errors) error.errors = errors
  throw error
}
function assert(condition, status, message = 'Request could not be completed.') { if (!condition) fail(status, message) }
function userView(user) {
  return { id: user.id, full_name: user.full_name, email: user.email, role: user.role, section: user.section, grade_level: user.grade_level, profile_image: user.profile_image }
}
function fileExt(file) {
  const types = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/gif': 'gif', 'image/webp': 'webp', 'application/pdf': 'pdf', 'application/msword': 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx', 'audio/mpeg': 'mp3', 'application/vnd.ms-powerpoint': 'ppt', 'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'pptx', 'application/vnd.ms-excel': 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx' }
  return types[file.mimetype] || path.extname(file.originalname).slice(1).toLowerCase()
}
function assertStudentPhoto(file) {
  if (file) assert(file.size > 0 && ['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.mimetype), 422, 'Upload a JPG, PNG, GIF, or WebP image.')
}
function safeFilename(value) { return path.basename(String(value || 'file').replace(/[^\w. -]/g, '_')) }
async function saveUpload(file, folder, prefix = 'file') {
  assert(file, 422, 'A file is required.')
  const ext = fileExt(file)
  const filename = `${prefix}_${Date.now()}_${crypto.randomBytes(5).toString('hex')}.${ext || 'bin'}`
  const relative = `uploads/${folder}/${filename}`
  const target = path.join(storageRoot, ...relative.split('/'))
  await fs.mkdir(path.dirname(target), { recursive: true })
  await fs.writeFile(target, file.buffer)
  return { relative, filename, size: file.size }
}
function filePath(relative) { return path.resolve(storageRoot, String(relative || '').replace(/^[/\\]+/, '')) }
function sendFile(res, relative, download = false) {
  const target = filePath(relative)
  assert(target.startsWith(storageRoot + path.sep), 400, 'Invalid file path.')
  return res.sendFile(target, { headers: download ? { 'Content-Disposition': `attachment; filename="${safeFilename(path.basename(target))}"` } : undefined }, (error) => {
    if (error && !res.headersSent) res.status(error.statusCode || 404).json({ message: 'File not found.' })
  })
}
async function paginated(req, sql, params = [], countSql, defaultSize = 25) {
  if (!('page' in req.query) && !('per_page' in req.query)) return null
  const { page, perPage, offset } = pageBounds(req.query.page, req.query.per_page, defaultSize)
  if (!countSql) throw new Error('A count query is required for paginated endpoints.')
  // Keep the page query and total count in one TiDB round trip. LIMIT/OFFSET
  // are parsed, bounded integers because TiDB rejects them as bound parameters.
  const pageRows = await query(
    `SELECT page_rows.*, (${countSql}) AS __pagination_total FROM (${sql} LIMIT ${perPage} OFFSET ${offset}) AS page_rows`,
    [...params, ...params],
  )
  // A scalar total is attached to each returned row. Only an empty page needs
  // a fallback count query, so out-of-range pages still report the true total.
  const total = Number(pageRows[0]?.__pagination_total ?? (await one(countSql, params))?.total ?? 0)
  const data = pageRows.map(({ __pagination_total, ...row }) => row)
  return { data, pagination: { current_page: page, last_page: Math.max(1, Math.ceil(total / perPage)), per_page: perPage, total } }
}
function pageBounds(pageValue, sizeValue, defaultSize = 25) {
  const requestedSize = Number.parseInt(sizeValue || String(defaultSize), 10)
  const perPage = Math.min(100, Math.max(1, Number.isSafeInteger(requestedSize) ? requestedSize : defaultSize))
  const requestedPage = Number.parseInt(pageValue || '1', 10)
  const maxPage = Math.floor(Number.MAX_SAFE_INTEGER / perPage)
  const page = Math.min(maxPage, Math.max(1, Number.isSafeInteger(requestedPage) ? requestedPage : 1))
  return { page, perPage, offset: (page - 1) * perPage }
}
function searchLike(value) { return `%${String(value || '').trim().slice(0, 100)}%` }
function dateOr(value, fallback) { return value || fallback }
function validateDate(value, field) {
  const date = String(value || '')
  const parsed = /^\d{4}-\d{2}-\d{2}$/.test(date) ? new Date(`${date}T00:00:00.000Z`) : null
  if (!parsed || Number.isNaN(parsed.getTime()) || parsed.toISOString().slice(0, 10) !== date) {
    fail(422, `The ${field} field must be a valid date.`, { [field]: ['The field must be a valid date.'] })
  }
}
function requireRole(req, roles) { assert(roles.includes(req.user.role), 403, 'You are not authorized to perform this action.') }
function requireTeacherOwn(req, student) { assert(req.user.role !== 'teacher' || Number(student.teacher_id) === Number(req.user.id), 403) }
function uploadSize(file) { return `${(file.size / 1048576).toFixed(2)} MB` }

app.get('/up', (_req, res) => res.status(200).send('OK'))
app.get('/api/health', (_req, res) => res.json({ status: 'ok' }))

app.post('/api/auth/login', async (req, res) => {
  const email = String(req.body.email || '').trim().toLowerCase()
  const password = String(req.body.password || '')
  if (!email || !password) fail(422, 'Invalid email or password.', { email: ['Email and password are required.'] })
  const user = await one('SELECT * FROM users WHERE email = ? LIMIT 1', [email])
  const hash = String(user?.password || '').replace(/^\$2y\$/, '$2b$')
  if (!user || !(await bcrypt.compare(password, hash))) fail(422, 'Invalid email or password.', { email: ['Invalid email or password.'] })
  if (user.status !== 'active') fail(403, 'Your account is pending approval or has been deactivated. Please contact your school administrator.')
  const plain = crypto.randomBytes(32).toString('hex')
  const tokenHash = crypto.createHash('sha256').update(plain).digest('hex')
  const tokenId = await insert('INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, abilities, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())', ['App\\Models\\User', user.id, 'reading-web', tokenHash, '["*"]'])
  res.json({ token: `${tokenId}|${plain}`, user: userView(user) })
})

app.post('/api/auth/register', async (req, res) => {
  const { role, first_name, last_name, grade_level, section, email, password, password_confirmation } = req.body
  const errors = {}
  if (!['principal', 'coordinator', 'teacher'].includes(role)) errors.role = ['Select a valid role.']
  if (!String(first_name || '').trim()) errors.first_name = ['First name is required.']
  if (!String(last_name || '').trim()) errors.last_name = ['Last name is required.']
  if (!/^\S+@\S+\.\S+$/.test(String(email || ''))) errors.email = ['Enter a valid email address.']
  if (role === 'teacher' && !grade_level) errors.grade_level = ['Grade level is required for teachers.']
  if (role === 'teacher' && !section) errors.section = ['Section is required for teachers.']
  if (String(password || '').length < 8 || !/[A-Z]/.test(password) || !/[0-9]/.test(password)) errors.password = ['Password must have at least 8 characters, one uppercase letter, and one number.']
  if (password !== password_confirmation) errors.password_confirmation = ['Password confirmation does not match.']
  if (Object.keys(errors).length) fail(422, 'The given data was invalid.', errors)
  if (await one('SELECT id FROM users WHERE email = ? LIMIT 1', [email])) fail(422, 'The email has already been taken.', { email: ['The email has already been taken.'] })
  const fullName = `${first_name.trim()} ${last_name.trim()}`
  const id = await insert('INSERT INTO users (full_name,email,password,role,section,grade_level,status,joined_date) VALUES (?,?,?,?,?,?,\'inactive\',CURDATE())', [fullName, email, await bcrypt.hash(password, 10), role, section || null, grade_level || null])
  const details = { Role: role[0].toUpperCase() + role.slice(1), Name: fullName, Email: email }
  if (role === 'teacher') details['Assigned To'] = `${grade_level || ''} — ${section || ''}`.trim()
  res.status(201).json({ message: 'Registration submitted successfully. Your account is pending approval.', details, id })
})

app.use('/api', async (req, _res, next) => {
  if (req.path === '/health' || req.path === '/auth/login' || req.path === '/auth/register') return next()
  try {
    const header = String(req.headers.authorization || '')
    const match = header.match(/^Bearer\s+(\d+)\|([a-f0-9]+)$/i)
    if (!match) return fail(401, 'Unauthenticated.')
    const tokenHash = crypto.createHash('sha256').update(match[2]).digest('hex')
    let user = cachedAuthUser(match[1], tokenHash)
    if (!user) {
      user = await one("SELECT u.id,u.full_name,u.email,u.role,u.status,u.section,u.grade_level,u.profile_image,u.contact_number,u.joined_date,p.id AS api_token_id FROM personal_access_tokens p JOIN users u ON u.id = p.tokenable_id WHERE p.id = ? AND p.token = ? AND p.tokenable_type = ? AND u.status = 'active' AND (p.expires_at IS NULL OR p.expires_at > NOW()) LIMIT 1", [match[1], tokenHash, 'App\\Models\\User'])
      if (user) rememberAuthUser(match[1], tokenHash, user)
    }
    if (!user) return fail(401, 'Unauthenticated.')
    req.user = user
    req.apiTokenId = user.api_token_id
    const isWrite = !['GET', 'HEAD', 'OPTIONS'].includes(req.method.toUpperCase())
    const principalSelfService = ['/auth/logout', '/profile', '/profile/password', '/profile/image'].includes(req.path)
    const reportGeneration = req.path === '/reports' && req.method === 'POST'
    if (user.role === 'principal' && isWrite && !principalSelfService && !reportGeneration) return fail(403, 'Principal accounts have view-only access.')
    next()
  } catch (error) { next(error) }
})

app.get('/api/auth/me', (req, res) => res.json({ user: userView(req.user) }))
app.post('/api/auth/logout', async (req, res) => { await query('DELETE FROM personal_access_tokens WHERE id = ?', [req.apiTokenId]); invalidateAuthUser(req.apiTokenId); res.json({ message: 'Logged out.' }) })

app.get('/api/students', async (req, res) => {
  if (req.query.options === '1' || req.query.options === 'true') {
    const params = req.user.role === 'teacher' ? [req.user.id] : []
    const where = req.user.role === 'teacher' ? ' WHERE teacher_id = ?' : ''
    return res.json({ data: await query(`SELECT id,full_name,grade_level,section FROM students${where} ORDER BY grade_level,section,full_name`, params) })
  }
  const params = []
  let where = ' WHERE 1=1'
  if (req.user.role === 'teacher') { where += ' AND s.teacher_id = ?'; params.push(req.user.id) }
  if (req.query.section && req.query.section !== 'All Sections') { where += ' AND s.section = ?'; params.push(req.query.section) }
  if (req.query.reading_level) { where += ' AND s.reading_profile = ?'; params.push(req.query.reading_level) }
  if (req.query.search) { const term = searchLike(req.query.search); where += ' AND (s.full_name LIKE ? OR s.lrn LIKE ?)'; params.push(term, term) }
  const sql = `SELECT s.id,s.lrn,s.full_name,s.section,s.grade_level,s.teacher_id,s.date_enrolled,s.profile_image,s.parent_guardian,s.contact_number,s.reading_profile,s.score,t.full_name AS teacher_name,(SELECT a.assessment_date FROM assessments a WHERE a.student_id=s.id ORDER BY a.assessment_date DESC,a.id DESC LIMIT 1) AS latest_assessment FROM students s LEFT JOIN users t ON t.id=s.teacher_id${where} ORDER BY s.grade_level,s.section,s.full_name`
  const isPaginated = 'page' in req.query || 'per_page' in req.query
  const includeSections = req.query.include_options === '1' || !isPaginated
  const [page, sections, teachers] = await Promise.all([
    isPaginated ? paginated(req, sql, params, `SELECT COUNT(*) AS total FROM students s${where}`) : query(sql, params),
    includeSections ? query("SELECT DISTINCT section FROM students WHERE section IS NOT NULL AND section <> '' ORDER BY section") : Promise.resolve([]),
    adminRoles.includes(req.user.role) ? query("SELECT id,full_name,section,grade_level FROM users WHERE role='teacher' AND status='active' ORDER BY full_name") : Promise.resolve([]),
  ])
  const payload = page?.data ? page : { data: page }
  payload.sections = sections.map((row) => row.section)
  if (adminRoles.includes(req.user.role)) payload.teachers = teachers
  res.json(payload)
})

app.post('/api/students', imageUpload.single('profile_image'), async (req, res) => {
  requireRole(req, adminRoles)
  const body = req.body
  assertStudentPhoto(req.file)
  assert(body.full_name && body.lrn && body.section && body.grade_level, 422, 'Pupil name, LRN, section, and grade level are required.')
  assert(/^\d{12}$/.test(String(body.lrn)), 422, 'LRN must contain exactly 12 digits.')
  if (await one('SELECT id FROM students WHERE lrn = ?', [body.lrn])) fail(422, 'The LRN has already been taken.')
  let teacherId = body.teacher_id || null
  if (teacherId) assert(await one("SELECT id FROM users WHERE id=? AND role='teacher' AND status='active'", [teacherId]), 422, 'Select an active teacher.')
  let imagePath = null
  if (req.file) imagePath = (await saveUpload(req.file, 'students', 'student')).relative
  const id = await insert('INSERT INTO students (lrn,full_name,section,grade_level,teacher_id,date_enrolled,parent_guardian,contact_number,profile_image) VALUES (?,?,?,?,?,?,?,?,?)', [body.lrn, body.full_name, body.section, body.grade_level, teacherId, body.date_enrolled || new Date().toISOString().slice(0, 10), body.parent_guardian || null, body.contact_number || null, imagePath])
  res.status(201).json({ data: await one('SELECT * FROM students WHERE id=?', [id]) })
})

app.get('/api/students/:id/details', async (req, res) => {
  const student = await one('SELECT s.*,u.full_name AS teacher_name FROM students s LEFT JOIN users u ON u.id=s.teacher_id WHERE s.id=?', [req.params.id])
  assert(student, 404, 'Pupil not found.')
  requireTeacherOwn(req, student)
  const [assessments, progress, endorsements, sessions, attendance] = await Promise.all([
    query('SELECT * FROM assessments WHERE student_id=? ORDER BY assessment_date DESC,id DESC', [student.id]),
    query('SELECT * FROM progress_updates WHERE student_id=? ORDER BY update_date DESC,id DESC', [student.id]),
    query('SELECT * FROM endorsements WHERE student_id=? ORDER BY created_at DESC,id DESC', [student.id]),
    query('SELECT * FROM remedial_sessions WHERE student_id=? ORDER BY session_date DESC,id DESC', [student.id]),
    query('SELECT a.*,r.focus_area,r.session_type,u.full_name AS recorded_by_name FROM remedial_attendance a LEFT JOIN remedial_sessions r ON r.id=a.session_id LEFT JOIN users u ON u.id=a.recorded_by WHERE a.student_id=? ORDER BY a.attendance_date DESC,a.id DESC', [student.id]),
  ])
  res.json({ student, assessments, progress, endorsements, sessions, attendance })
})
app.get('/api/students/:id/image', async (req, res) => {
  const student = await one('SELECT teacher_id,profile_image FROM students WHERE id=?', [req.params.id])
  assert(student?.profile_image, 404, 'Image not found.')
  requireTeacherOwn(req, student)
  sendFile(res, student.profile_image)
})
app.get('/api/students/:id', async (req, res) => {
  const student = await one('SELECT * FROM students WHERE id=?', [req.params.id])
  assert(student, 404, 'Pupil not found.')
  requireTeacherOwn(req, student)
  res.json({ data: student })
})
app.put('/api/students/:id', imageUpload.single('profile_image'), async (req, res) => {
  requireRole(req, ['coordinator'])
  assertStudentPhoto(req.file)
  const fields = ['full_name','lrn','section','grade_level','date_enrolled','parent_guardian','contact_number','teacher_id']
  const data = Object.fromEntries(fields.filter((key) => key in req.body).map((key) => [key, req.body[key] || null]))
  assert(data.full_name && data.lrn && data.section && data.grade_level && data.teacher_id, 422, 'Required student fields are missing.')
  assert(/^\d{12}$/.test(String(data.lrn)), 422, 'LRN must contain exactly 12 digits.')
  const duplicate = await one('SELECT id FROM students WHERE lrn=? AND id<>?', [data.lrn, req.params.id])
  assert(!duplicate, 422, 'The LRN has already been taken.')
  assert(await one("SELECT id FROM users WHERE id=? AND role='teacher' AND status='active'", [data.teacher_id]), 422, 'Select an active teacher.')
  const profileImage = req.file ? (await saveUpload(req.file, 'students', 'student')).relative : null
  await query('UPDATE students SET full_name=?,lrn=?,section=?,grade_level=?,date_enrolled=?,parent_guardian=?,contact_number=?,teacher_id=?,profile_image=COALESCE(?,profile_image) WHERE id=?', [...fields.map((key) => data[key] ?? null), profileImage, req.params.id])
  const row = await one('SELECT * FROM students WHERE id=?', [req.params.id]); assert(row, 404, 'Pupil not found.')
  res.json({ data: row })
})
app.delete('/api/students/:id', async (req, res) => {
  requireRole(req, adminRoles)
  const result = await query('DELETE FROM students WHERE id=?', [req.params.id]); assert(result.affectedRows, 404, 'Pupil not found.')
  res.json({ message: 'Pupil removed.' })
})

app.get('/api/dashboard', async (req, res) => {
  const teacher = req.user.role === 'teacher'
  const scope = teacher ? ' WHERE teacher_id=?' : ''
  const scopeParams = teacher ? [req.user.id] : []
  const studentScope = teacher ? 's.teacher_id=? AND ' : ''
  const metricsSql = `SELECT sm.students,sm.independent,sm.instructional,sm.frustration,
    (SELECT COUNT(*) FROM assessments a JOIN students s ON s.id=a.student_id WHERE ${studentScope}1=1) AS assessments,
    (SELECT COUNT(*) FROM endorsements WHERE status='Pending'${teacher ? ' AND teacher_id=?' : ''}) AS pending_endorsements,
    (SELECT COUNT(*) FROM remedial_sessions rs JOIN students s ON s.id=rs.student_id WHERE ${studentScope}1=1) AS remedial_sessions,
    (SELECT COUNT(DISTINCT s.id) FROM students s JOIN remedial_sessions rs ON rs.student_id=s.id WHERE ${studentScope}rs.status IN ('Scheduled','In Progress')) AS remedial_pupils,
    ${teacher ? '0' : "(SELECT COUNT(*) FROM users WHERE role='teacher' AND status='active')"} AS teachers,
    attendance.present_attendance,attendance.absent_attendance
    FROM (SELECT COUNT(*) students,COALESCE(SUM(reading_profile='Independent'),0) independent,COALESCE(SUM(reading_profile='Instructional'),0) instructional,COALESCE(SUM(reading_profile='Frustration'),0) frustration FROM students${scope}) sm
    CROSS JOIN (SELECT COALESCE(SUM(ra.status='Present'),0) present_attendance,COALESCE(SUM(ra.status='Absent'),0) absent_attendance FROM remedial_attendance ra${teacher ? ' JOIN remedial_sessions rs ON rs.id=ra.session_id WHERE rs.teacher_id=?' : ''}) attendance`
  const metricsParams = [
    ...scopeParams,
    ...scopeParams,
    ...(teacher ? [req.user.id] : []),
    ...scopeParams,
    ...scopeParams,
    ...(teacher ? [req.user.id] : []),
  ]
  const [metrics, grades, recentAssessments, support, progress, upcoming, materials] = await Promise.all([
    one(metricsSql, metricsParams),
    query(`SELECT grade_level,SUM(reading_profile='Independent') independent,SUM(reading_profile='Instructional') instructional,SUM(reading_profile='Frustration') frustration FROM students${scope} GROUP BY grade_level ORDER BY grade_level`, scopeParams),
    query(`SELECT a.id,s.full_name,s.grade_level,a.tool,a.total_score,a.profile,a.assessment_date FROM assessments a JOIN students s ON s.id=a.student_id WHERE ${studentScope}1=1 ORDER BY a.assessment_date DESC,a.id DESC LIMIT 5`, scopeParams),
    query(`SELECT id,full_name,grade_level,section,reading_profile,score FROM students${scope}${teacher ? ' AND' : ' WHERE'} reading_profile IN ('Frustration','Low Emerging Reader') ORDER BY full_name LIMIT 5`, scopeParams),
    query(`SELECT pu.id,pu.student_id,pu.previous_profile,pu.current_profile,pu.trend,pu.update_date,s.full_name,s.grade_level,s.section FROM progress_updates pu JOIN students s ON s.id=pu.student_id WHERE ${studentScope}1=1 ORDER BY pu.update_date DESC LIMIT 6`, scopeParams),
    query(`SELECT rs.id,rs.session_date,rs.start_time,rs.session_type,rs.status,s.full_name,s.grade_level,s.section FROM remedial_sessions rs JOIN students s ON s.id=rs.student_id WHERE ${studentScope}rs.session_date>=CURDATE() ORDER BY rs.session_date,rs.start_time LIMIT 5`, scopeParams),
    query(`SELECT lm.title,lm.material_type,md.given_to,md.grade_level,md.date_given,u.full_name given_by_name FROM material_distribution md JOIN learning_materials lm ON lm.id=md.material_id LEFT JOIN users u ON u.id=md.given_by LEFT JOIN students s ON s.full_name=md.given_to WHERE ${teacher ? '(md.given_by=? OR s.teacher_id=?) AND ' : ''}1=1 ORDER BY md.date_given DESC LIMIT 6`, teacher ? [req.user.id, req.user.id] : []),
  ])
  const present = Number(metrics.present_attendance || 0), absent = Number(metrics.absent_attendance || 0)
  res.json({ role: req.user.role, metrics: { students: Number(metrics.students), teachers: Number(metrics.teachers), assessments: Number(metrics.assessments), pending_endorsements: Number(metrics.pending_endorsements), remedial_sessions: Number(metrics.remedial_sessions), independent: Number(metrics.independent), instructional: Number(metrics.instructional), frustration: Number(metrics.frustration), remedial_pupils: Number(metrics.remedial_pupils), present_attendance: present, absent_attendance: absent, attendance_rate: present + absent ? Math.round(present / (present + absent) * 100) : 0 }, recent_assessments: recentAssessments, students_needing_support: support, intervention_pupils: support.filter((s) => s.reading_profile === 'Frustration'), student_overview: [], level_distribution: { independent: Number(metrics.independent), instructional: Number(metrics.instructional), frustration: Number(metrics.frustration) }, grade_distribution: grades, recent_progress: progress, upcoming_sessions: upcoming, material_history: materials })
})

app.get('/api/assessments', async (req, res) => {
  const params = []; let where = ' WHERE 1=1'
  if (req.user.role === 'teacher') { where += ' AND s.teacher_id=?'; params.push(req.user.id) }
  if (req.query.search) { const term = searchLike(req.query.search); where += ' AND (s.full_name LIKE ? OR s.grade_level LIKE ? OR a.tool LIKE ? OR a.profile LIKE ?)'; params.push(term, term, term, term) }
  const sql = `SELECT a.*,s.full_name,s.grade_level,s.section,u.full_name recorded_by_name FROM assessments a JOIN students s ON s.id=a.student_id LEFT JOIN users u ON u.id=a.recorded_by${where} ORDER BY a.assessment_date DESC,a.id DESC`
  const countSql = `SELECT COUNT(*) AS total FROM assessments a JOIN students s ON s.id=a.student_id${where}`
  const page = await paginated(req, sql, params, countSql); res.json(page || { data: await query(sql, params) })
})
app.post('/api/assessments', async (req, res) => {
  const b = req.body; const student = await one('SELECT * FROM students WHERE id=?', [b.student_id]); assert(student, 422, 'Pupil not found.'); requireTeacherOwn(req, student)
  for (const key of ['assessment_date','tool','profile']) assert(b[key], 422, `The ${key} field is required.`)
  const score = ['task1_score','words_score','sentences_score'].map((key) => Number(b[key] || 0)); assert(score.every((x) => Number.isInteger(x) && x >= 0), 422, 'Scores must be non-negative integers.')
  const id = await transaction(async (conn) => {
    const [result] = await conn.execute('INSERT INTO assessments (student_id,tool,task1_score,words_score,sentences_score,total_score,profile,assessment_date,recorded_by) VALUES (?,?,?,?,?,?,?,?,?)', [student.id, b.tool, ...score, score.reduce((a,c)=>a+c,0), b.profile, b.assessment_date, req.user.id])
    await conn.execute('UPDATE students SET reading_profile=?,score=? WHERE id=?', [b.profile, score.reduce((a,c)=>a+c,0), student.id]); return result.insertId
  })
  res.status(201).json({ data: await one('SELECT * FROM assessments WHERE id=?', [id]) })
})
app.get('/api/progress-updates', async (req, res) => {
  const params = []; let where = ' WHERE 1=1'
  if (req.user.role === 'teacher') { where += ' AND s.teacher_id=?'; params.push(req.user.id) }
  if (req.query.search) { const term = searchLike(req.query.search); where += ' AND (s.full_name LIKE ? OR s.grade_level LIKE ? OR p.current_profile LIKE ? OR p.trend LIKE ?)'; params.push(term,term,term,term) }
  const sql = `SELECT p.*,s.full_name,s.grade_level,s.section,u.full_name updated_by_name FROM progress_updates p JOIN students s ON s.id=p.student_id LEFT JOIN users u ON u.id=p.updated_by${where} ORDER BY p.update_date DESC,p.id DESC`
  const countSql = `SELECT COUNT(*) AS total FROM progress_updates p JOIN students s ON s.id=p.student_id${where}`
  const page = await paginated(req,sql,params,countSql); res.json(page || {data:await query(sql,params)})
})
app.post('/api/progress-updates', async (req,res) => {
  const b=req.body; const student=await one('SELECT * FROM students WHERE id=?',[b.student_id]); assert(student,422,'Pupil not found.'); requireTeacherOwn(req,student)
  for(const k of ['previous_profile','current_profile','trend','update_date']) assert(b[k],422,`The ${k} field is required.`)
  const id=await transaction(async conn=>{const [r]=await conn.execute('INSERT INTO progress_updates (student_id,previous_profile,current_profile,trend,update_date,updated_by,remarks) VALUES (?,?,?,?,?,?,?)',[student.id,b.previous_profile,b.current_profile,b.trend,b.update_date,req.user.id,b.remarks||null]);await conn.execute('UPDATE students SET reading_profile=? WHERE id=?',[b.current_profile,student.id]);return r.insertId})
  res.status(201).json({data:await one('SELECT * FROM progress_updates WHERE id=?',[id])})
})
app.get('/api/grade-categories', async (_req,res)=>res.json({data:await query('SELECT * FROM grade_categories ORDER BY grade_level,category_name')}))
app.post('/api/grade-categories', async (req,res)=>{
  requireRole(req,adminRoles);const b=req.body;assert(b.grade_level&&b.category_name&&b.score_range&&b.recommended_action,422,'Required category fields are missing.')
  const id=await insert('INSERT INTO grade_categories (grade_level,assessment_type,category_name,score_range,recommended_action,min_score,max_score,description) VALUES (?,?,?,?,?,?,?,?)',[b.grade_level,b.assessment_type||null,b.category_name,b.score_range,b.recommended_action,b.min_score||0,b.max_score||100,b.description||null]);res.status(201).json({data:await one('SELECT * FROM grade_categories WHERE id=?',[id])})
})
app.put('/api/grade-categories/:id', async (req,res)=>{
  requireRole(req,adminRoles);const b=req.body;const old=await one('SELECT id FROM grade_categories WHERE id=?',[req.params.id]);assert(old,404,'Category not found.')
  await query('UPDATE grade_categories SET grade_level=?,assessment_type=?,category_name=?,score_range=?,recommended_action=?,min_score=?,max_score=?,description=? WHERE id=?',[b.grade_level,b.assessment_type||null,b.category_name,b.score_range,b.recommended_action,b.min_score||0,b.max_score||100,b.description||null,req.params.id]);res.json({data:await one('SELECT * FROM grade_categories WHERE id=?',[req.params.id])})
})
app.delete('/api/grade-categories/:id', async (req,res)=>{requireRole(req,adminRoles);const r=await query('DELETE FROM grade_categories WHERE id=?',[req.params.id]);assert(r.affectedRows,404,'Category not found.');res.json({message:'Category removed.'})})
app.get('/api/schedule', async (req,res)=>{
  const params=[];let where=' WHERE 1=1';if(req.user.role==='teacher'){where+=' AND e.teacher_id=?';params.push(req.user.id)}
  if(req.query.from&&req.query.to){where+=' AND e.event_date BETWEEN ? AND ?';params.push(req.query.from,req.query.to)}
  const data=await query(`SELECT e.*,u.full_name teacher_name FROM schedule_events e LEFT JOIN users u ON u.id=e.teacher_id${where} ORDER BY e.event_date,e.start_time`,params);res.json({data})
})
app.post('/api/schedule', async (req,res)=>{
  const b=req.body;assert(b.title&&b.event_date&&b.start_time&&b.end_time,422,'Event title, date, and times are required.');if(req.user.role==='teacher')b.teacher_id=req.user.id;else requireRole(req,adminRoles)
  const id=await insert('INSERT INTO schedule_events (title,event_type,grade_level,section,event_date,start_time,end_time,teacher_id,description) VALUES (?,?,?,?,?,?,?,?,?)',[b.title,b.event_type||null,b.grade_level||null,b.section||null,b.event_date,b.start_time,b.end_time,b.teacher_id||null,b.description||null]);res.status(201).json({data:await one('SELECT * FROM schedule_events WHERE id=?',[id])})
})

app.get('/api/remedial-sessions', async (req,res)=>{
  const p=[];let w=' WHERE 1=1';if(req.user.role==='teacher'){w+=' AND s.teacher_id=?';p.push(req.user.id)}
  if(req.query.search){const q=searchLike(req.query.search);w+=' AND (s.full_name LIKE ? OR s.grade_level LIKE ? OR r.focus_area LIKE ? OR r.session_type LIKE ? OR r.status LIKE ?)';p.push(q,q,q,q,q)}
  const sql=`SELECT r.*,s.full_name,s.grade_level,s.section,u.full_name teacher_name FROM remedial_sessions r JOIN students s ON s.id=r.student_id LEFT JOIN users u ON u.id=r.teacher_id${w} ORDER BY r.session_date DESC,r.id DESC`
  const countSql=`SELECT COUNT(*) AS total FROM remedial_sessions r JOIN students s ON s.id=r.student_id${w}`
  const page=await paginated(req,sql,p,countSql);res.json(page||{data:await query(sql,p)})
})
app.post('/api/remedial-sessions', async (req,res)=>{
  const b=req.body;const student=await one('SELECT * FROM students WHERE id=?',[b.student_id]);assert(student,422,'Pupil not found.')
  let teacherId=b.teacher_id||student.teacher_id||null;if(req.user.role==='teacher'){requireTeacherOwn(req,student);teacherId=req.user.id}else requireRole(req,adminRoles)
  assert(b.session_date&&b.start_time&&b.end_time&&b.focus_area&&b.session_type,422,'Required session fields are missing.')
  const id=await insert("INSERT INTO remedial_sessions (student_id,teacher_id,session_date,start_time,end_time,focus_area,session_type,status,notes) VALUES (?,?,?,?,?,?,?,'Scheduled',?)",[student.id,teacherId,b.session_date,b.start_time,b.end_time,b.focus_area,b.session_type,b.notes||null]);res.status(201).json({data:await one('SELECT * FROM remedial_sessions WHERE id=?',[id])})
})
app.get('/api/attendance', async (req,res)=>{
  const teacher=req.user.role==='teacher';const base=teacher?' AND s.teacher_id=?':'';const args=teacher?[req.user.id]:[]
  if(req.query.from)validateDate(req.query.from,'from')
  if(req.query.to)validateDate(req.query.to,'to')
  assert(!req.query.from||!req.query.to||req.query.from<=req.query.to,422,'Start date must be on or before end date.')
  const sessionDateFilter=(req.query.from?' AND r.session_date>=?':'')+(req.query.to?' AND r.session_date<=?':'')
  const historyDateFilter=(req.query.from?' AND a.attendance_date>=?':'')+(req.query.to?' AND a.attendance_date<=?':'')
  const searchFilter=req.query.search?' AND (s.full_name LIKE ? OR s.lrn LIKE ?)':''
  const searchParams=req.query.search?[searchLike(req.query.search),searchLike(req.query.search)]:[]
  const sessionArgs=[...args,...(req.query.from?[req.query.from]:[]),...(req.query.to?[req.query.to]:[]),...searchParams]
  const historyArgs=[...args,...(req.query.from?[req.query.from]:[]),...(req.query.to?[req.query.to]:[]),...searchParams]
  const sessionPageRequested='sessions_page' in req.query
  const { page: sessionPage, perPage: pageSize, offset: sessionOffset } = pageBounds(req.query.sessions_page, req.query.per_page)
  const sessionSelect='SELECT r.id,r.session_date,r.start_time,r.end_time,s.id student_id,s.full_name,s.grade_level,s.section,t.full_name teacher_name,a.status attendance_status,a.attendance_date,a.remarks FROM remedial_sessions r JOIN students s ON s.id=r.student_id LEFT JOIN users t ON t.id=r.teacher_id LEFT JOIN (SELECT session_id,MAX(id) attendance_id FROM remedial_attendance GROUP BY session_id) latest_attendance ON latest_attendance.session_id=r.id LEFT JOIN remedial_attendance a ON a.id=latest_attendance.attendance_id WHERE 1=1'+base+sessionDateFilter+searchFilter+' ORDER BY r.session_date DESC,r.id DESC'
  const historySql=`SELECT a.*,s.full_name,s.grade_level,s.section,u.full_name recorded_by_name FROM remedial_attendance a JOIN students s ON s.id=a.student_id LEFT JOIN users u ON u.id=a.recorded_by WHERE 1=1${base}${historyDateFilter}${searchFilter} ORDER BY a.attendance_date DESC,a.id DESC`
  const { page: historyPageNumber, perPage: historySize, offset: historyOffset } = pageBounds(req.query.history_page, req.query.per_page)
  const sessionQuery=sessionPageRequested
    ? query(`${sessionSelect} LIMIT ${pageSize} OFFSET ${sessionOffset}`,sessionArgs)
    : query(sessionSelect,sessionArgs)
  const sessionCountQuery=sessionPageRequested
    ? one(`SELECT COUNT(*) total FROM remedial_sessions r JOIN students s ON s.id=r.student_id WHERE 1=1${base}${sessionDateFilter}${searchFilter}`,sessionArgs)
    : Promise.resolve(null)
  const [sessions,sessionTotal,historySummary,historyRows]=await Promise.all([
    sessionQuery,
    sessionCountQuery,
    one(`SELECT COUNT(*) total,COALESCE(SUM(a.status='Present'),0) present,COALESCE(SUM(a.status='Absent'),0) absent FROM remedial_attendance a JOIN students s ON s.id=a.student_id WHERE 1=1${base}${historyDateFilter}${searchFilter}`,historyArgs),
    query(`${historySql} LIMIT ${historySize} OFFSET ${historyOffset}`,historyArgs),
  ])
  if(sessionPageRequested)sessions.pagination={current_page:sessionPage,last_page:Math.max(1,Math.ceil(Number(sessionTotal.total)/pageSize)),per_page:pageSize,total:Number(sessionTotal.total)}
  const historyPage={data:historyRows,pagination:{current_page:historyPageNumber,last_page:Math.max(1,Math.ceil(Number(historySummary.total)/historySize)),per_page:historySize,total:Number(historySummary.total)}}
  const result={sessions,history:historyPage.data,session_pagination:sessions.pagination||null,history_pagination:historyPage.pagination,history_counts:{Present:Number(historySummary.present),Absent:Number(historySummary.absent)}}
  res.json(result)
})
app.post('/api/attendance', async (req,res)=>{
  requireRole(req,['teacher'])
  const b=req.body;const sessionId=Number(b.session_id);assert(Number.isSafeInteger(sessionId)&&sessionId>0,422,'Select a valid remedial session.')
  const session=await one(`SELECT r.id,r.student_id,r.session_date,s.teacher_id FROM remedial_sessions r JOIN students s ON s.id=r.student_id WHERE r.id=?`,[sessionId]);assert(session,403,'That remedial session is not available for attendance recording.');requireTeacherOwn(req,session)
  validateDate(b.attendance_date,'attendance_date');assert(b.attendance_date===session.session_date,422,'Attendance date must match the remedial session date.')
  assert(['Present','Absent'].includes(b.status),422,'Choose Present or Absent for attendance status.')
  await query('INSERT INTO remedial_attendance (session_id,student_id,attendance_date,status,remarks,recorded_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),remarks=VALUES(remarks),recorded_by=VALUES(recorded_by)',[session.id,session.student_id,session.session_date,b.status,b.remarks||'',req.user.id]);res.json({message:'Attendance recorded.'})
})

app.get('/api/endorsements', async (req,res)=>{
  const reviewer=adminRoles.includes(req.user.role);const p=[];let w=' WHERE 1=1'
  if(!reviewer){w+=' AND e.teacher_id=?';p.push(req.user.id)}
  if(req.query.status&&req.query.status!=='All'){w+=' AND e.status=?';p.push(req.query.status)}
  if(req.query.search){const q=searchLike(req.query.search);w+=' AND (s.full_name LIKE ? OR s.grade_level LIKE ? OR t.full_name LIKE ? OR e.assessment_tool LIKE ? OR e.reading_level LIKE ?)';p.push(q,q,q,q,q)}
  const sql=`SELECT e.*,s.full_name,s.grade_level,s.section,t.full_name teacher_name FROM endorsements e JOIN students s ON s.id=e.student_id JOIN users t ON t.id=e.teacher_id${w} ORDER BY FIELD(e.status,'Pending','Returned for Revision','Approved','Rejected'),e.created_at DESC,e.id DESC`
  const countSql=`SELECT COUNT(*) AS total FROM endorsements e JOIN students s ON s.id=e.student_id JOIN users t ON t.id=e.teacher_id${w}`
  const page=await paginated(req,sql,p,countSql);const data=page?.data||await query(sql,p)
  const countRows=await query(`SELECT status,COUNT(*) total FROM endorsements${reviewer?'':' WHERE teacher_id=?'} GROUP BY status`,reviewer?[]:[req.user.id]);const counts={All:0,Pending:0,Approved:0,'Returned for Revision':0,Rejected:0};for(const c of countRows){counts[c.status]=Number(c.total);counts.All+=Number(c.total)}
  res.json({data,counts,students:reviewer?[]:await query('SELECT id,full_name,grade_level,section FROM students WHERE teacher_id=? ORDER BY full_name',[req.user.id]),reviewer,...(page?{pagination:page.pagination}:{})})
})
app.post('/api/endorsements', upload.single('supporting_document'), async (req,res)=>{
  requireRole(req,['teacher']);const b=req.body;const student=await one('SELECT id FROM students WHERE id=? AND teacher_id=?',[b.student_id,req.user.id]);assert(student,403,'Select one of your assigned pupils.')
  assert(b.assessment_tool&&b.assessment_result&&b.reading_level,422,'Required endorsement fields are missing.')
  const saved=req.file?await saveUpload(req.file,'endorsements','endorsement'):null
  const id=await transaction(async c=>{const [r]=await c.execute("INSERT INTO endorsements (student_id,teacher_id,assessment_tool,assessment_date,assessment_result,reading_level,reading_difficulty,teacher_remarks,supporting_document,status) VALUES (?,?,?,?,?,?,?,?,?,'Pending')",[student.id,req.user.id,b.assessment_tool,b.assessment_date||null,b.assessment_result,b.reading_level,b.reading_difficulty||'',b.teacher_remarks||'',saved?.relative||null]);await c.execute("INSERT INTO endorsement_history (endorsement_id,status,changed_by,remarks) VALUES (?,'Pending',?,'Submitted by teacher')",[r.insertId,req.user.id]);return r.insertId})
  res.status(201).json({data:await one('SELECT * FROM endorsements WHERE id=?',[id])})
})
app.get('/api/endorsements/:id/history', async (req,res)=>{
  const e=await one('SELECT teacher_id FROM endorsements WHERE id=?',[req.params.id]);assert(e,404,'Endorsement not found.');assert(adminRoles.includes(req.user.role)||Number(e.teacher_id)===Number(req.user.id),403)
  res.json({data:await query('SELECT h.*,u.full_name changed_by_name FROM endorsement_history h JOIN users u ON u.id=h.changed_by WHERE h.endorsement_id=? ORDER BY h.created_at,h.id',[req.params.id])})
})
app.get('/api/endorsements/:id/document', async (req,res)=>{const e=await one('SELECT teacher_id,supporting_document FROM endorsements WHERE id=?',[req.params.id]);assert(e,404,'Endorsement not found.');assert(adminRoles.includes(req.user.role)||Number(e.teacher_id)===Number(req.user.id),403);assert(e.supporting_document,404,'Document not found.');sendFile(res,e.supporting_document,true)})
app.post('/api/endorsements/:id/review', async (req,res)=>{
  requireRole(req,adminRoles);const b=req.body;assert(['Approved','Returned for Revision','Rejected'].includes(b.status),422,'Choose a valid review status.');assert(await one('SELECT id FROM endorsements WHERE id=?',[req.params.id]),404,'Endorsement not found.')
  await transaction(async c=>{await c.execute('UPDATE endorsements SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?',[b.status,req.user.id,req.params.id]);await c.execute('INSERT INTO endorsement_history (endorsement_id,status,changed_by,remarks) VALUES (?,?,?,?)',[req.params.id,b.status,req.user.id,b.review_remarks||''])});res.json({message:'Endorsement status updated.'})
})
app.get('/api/endorsements/:id', async (req,res)=>{const reviewer=adminRoles.includes(req.user.role);const e=await one('SELECT e.*,s.full_name,s.lrn,s.grade_level,s.section,t.full_name teacher_name,v.full_name reviewer_name FROM endorsements e JOIN students s ON s.id=e.student_id JOIN users t ON t.id=e.teacher_id LEFT JOIN users v ON v.id=e.reviewed_by WHERE e.id=? AND (?=1 OR e.teacher_id=?)',[req.params.id,reviewer?1:0,req.user.id]);assert(e,404,'Endorsement not found.');res.json({data:e,reviewer})})

app.get('/api/materials', async (req,res)=>{
  const p=[];let w=' WHERE 1=1';if(req.query.search){const q=searchLike(req.query.search);w+=' AND (m.title LIKE ? OR m.material_type LIKE ? OR m.skill_focus LIKE ? OR m.description LIKE ?)';p.push(q,q,q,q)}
  for(const [key,col] of [['grade','grade_level'],['language','language'],['part','alignment_part'],['profile','reading_level']]){const v=String(req.query[key]||'').trim();if(v&&!['All Grades','All Profiles'].includes(v)){w+=` AND m.${col}=?`;p.push(v)}}
  const sql=`SELECT m.*,u.full_name uploader_name FROM learning_materials m LEFT JOIN users u ON u.id=m.uploaded_by${w} ORDER BY m.upload_date DESC,m.id DESC`;const countSql=`SELECT COUNT(*) AS total FROM learning_materials m${w}`
  const [page,languages]=await Promise.all([paginated(req,sql,p,countSql).then((result)=>result||query(sql,p)),query('SELECT DISTINCT language FROM learning_materials WHERE language IS NOT NULL ORDER BY language')])
  res.json({...page,languages:languages.map(r=>r.language)})
})
app.get('/api/materials/:id/file', async (req,res)=>{const m=await one('SELECT file_path FROM learning_materials WHERE id=?',[req.params.id]);assert(m?.file_path,404,'File not found.');sendFile(res,m.file_path,req.query.view!=='1')})
app.get('/api/materials/:id', async (req,res)=>{const m=await one('SELECT m.*,u.full_name uploader_name FROM learning_materials m LEFT JOIN users u ON u.id=m.uploaded_by WHERE m.id=?',[req.params.id]);assert(m,404,'Learning material not found.');res.json({data:m})})
app.post('/api/materials', upload.single('file'), async (req,res)=>{
  requireRole(req,adminRoles);const b=req.body;assert(b.title&&b.material_type&&b.grade_level&&b.language&&b.alignment_part&&b.skill_focus&&req.file,422,'Complete all required material fields and select a file.')
  const ext=fileExt(req.file).toLowerCase();const formats={pdf:'PDF',doc:'DOCX',docx:'DOCX',mp3:'MP3',ppt:'PPT',pptx:'PPT',xls:'XLSX',xlsx:'XLSX'};assert(formats[ext],422,'Unsupported file type.')
  const saved=await saveUpload(req.file,'materials',`material_${req.user.id}`);const id=await insert('INSERT INTO learning_materials (title,description,material_type,grade_level,reading_level,language,alignment_part,skill_focus,file_path,file_format,file_size,uploaded_by,upload_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,CURDATE())',[b.title,b.description||null,b.material_type,b.grade_level,b.reading_level||null,b.language,b.alignment_part,b.skill_focus,saved.relative,formats[ext],uploadSize(req.file),req.user.id]);res.status(201).json({data:await one('SELECT * FROM learning_materials WHERE id=?',[id])})
})
app.put('/api/materials/:id', async (req,res)=>{requireRole(req,adminRoles);const b=req.body;const r=await query('UPDATE learning_materials SET title=?,description=?,material_type=?,grade_level=?,reading_level=?,language=?,alignment_part=?,skill_focus=? WHERE id=?',[b.title,b.description||null,b.material_type,b.grade_level,b.reading_level||null,b.language,b.alignment_part,b.skill_focus,req.params.id]);assert(r.affectedRows||await one('SELECT id FROM learning_materials WHERE id=?',[req.params.id]),404,'Material not found.');res.json({data:await one('SELECT * FROM learning_materials WHERE id=?',[req.params.id])})})
app.delete('/api/materials/:id', async (req,res)=>{requireRole(req,adminRoles);const m=await one('SELECT file_path FROM learning_materials WHERE id=?',[req.params.id]);assert(m,404,'Material not found.');await query('DELETE FROM learning_materials WHERE id=?',[req.params.id]);if(m.file_path)await fs.rm(filePath(m.file_path),{force:true});res.json({message:'Material removed.'})})

app.get('/api/profile', async (req,res)=>{
  const isTeacher=req.user.role==='teacher';let stats
  if(isTeacher){const [students,sessions,assessments]=await Promise.all([one('SELECT COUNT(*) n FROM students WHERE teacher_id=?',[req.user.id]),one('SELECT COUNT(*) n FROM remedial_sessions WHERE teacher_id=?',[req.user.id]),one('SELECT COUNT(*) n FROM assessments a JOIN students s ON s.id=a.student_id WHERE s.teacher_id=?',[req.user.id])]);stats={total_students:Number(students.n),sessions:Number(sessions.n),assessments:Number(assessments.n)}}
  else {const [teachers,students,reports]=await Promise.all([one("SELECT COUNT(*) n FROM users WHERE role='teacher'"),one('SELECT COUNT(*) n FROM students'),one('SELECT COUNT(*) n FROM reports')]);stats={total_teachers:Number(teachers.n),total_students:Number(students.n),total_reports:Number(reports.n)}}
  res.json({user:Object.fromEntries(Object.entries(req.user).filter(([k])=>safeUserFields.split(', ').includes(k))),stats})
})
app.put('/api/profile', async (req,res)=>{const b=req.body;assert(b.full_name&&b.email,422,'Name and email are required.');const dup=await one('SELECT id FROM users WHERE email=? AND id<>?',[b.email,req.user.id]);assert(!dup,422,'Email is already in use.');await query('UPDATE users SET full_name=?,email=?,section=?,grade_level=?,contact_number=? WHERE id=?',[b.full_name,b.email,b.section||null,b.grade_level||null,b.contact_number||null,req.user.id]);invalidateUserAuth(req.user.id);res.json({message:'Profile updated successfully!',user:await one(`SELECT ${safeUserFields} FROM users WHERE id=?`,[req.user.id])})})
app.post('/api/profile/password', async (req,res)=>{const b=req.body;assert(b.current_password&&b.new_password&&b.confirm_password,422,'Complete all password fields.');assert(b.new_password===b.confirm_password,422,'Password confirmation does not match.');const stored=await one('SELECT password FROM users WHERE id=?',[req.user.id]);const h=String(stored?.password||'').replace(/^\$2y\$/,'$2b$');assert(await bcrypt.compare(b.current_password,h),422,'Current password is incorrect.');await query('UPDATE users SET password=? WHERE id=?',[await bcrypt.hash(b.new_password,10),req.user.id]);res.json({message:'Password changed successfully!'})})
app.post('/api/profile/image', imageUpload.single('profile_image'), async (req,res)=>{assert(req.file&&/^image\/(jpeg|png|gif|webp)$/.test(req.file.mimetype),422,'Upload a JPG, PNG, GIF, or WebP image.');const f=await saveUpload(req.file,'profiles',`profile_${req.user.id}`);await query('UPDATE users SET profile_image=? WHERE id=?',[f.relative,req.user.id]);invalidateUserAuth(req.user.id);res.json({message:'Profile image updated successfully!',profile_image:f.relative})})
app.post('/api/profile/toggle-status', async (req,res)=>{requireRole(req,adminRoles);const status=req.user.status==='active'?'inactive':'active';await query('UPDATE users SET status=? WHERE id=?',[status,req.user.id]);invalidateUserAuth(req.user.id);if(status==='inactive')await query('DELETE FROM personal_access_tokens WHERE id=?',[req.apiTokenId]);res.json({message:`Account status updated to ${status}.`,status})})
app.get('/api/users/:id/image', async (req,res)=>{const u=await one('SELECT profile_image FROM users WHERE id=?',[req.params.id]);assert(u?.profile_image,404,'Image not found.');sendFile(res,u.profile_image)})

app.get('/api/teachers', async (req,res)=>{
  requireRole(req,adminRoles);let p=[];let w=" WHERE u.role='teacher'";if(req.query.search){const q=searchLike(req.query.search);w+=' AND (u.full_name LIKE ? OR u.email LIKE ? OR u.section LIKE ? OR u.grade_level LIKE ?)';p=[q,q,q,q]}
  const sql=`SELECT u.id,u.full_name,u.email,u.section,u.grade_level,u.status,u.joined_date,COUNT(s.id) student_count FROM users u LEFT JOIN students s ON s.teacher_id=u.id${w} GROUP BY u.id ORDER BY u.joined_date DESC,u.id DESC`;const countSql=`SELECT COUNT(*) AS total FROM users u${w}`
  const listPromise=('page' in req.query||'per_page' in req.query)?paginated(req,sql,p,countSql):query(sql,p)
  const [list,principal]=await Promise.all([listPromise,one("SELECT id,full_name,email,status,joined_date FROM users WHERE role='principal' ORDER BY id LIMIT 1")])
  res.json({teachers:list?.data||list,principal,...(list?.pagination?{pagination:list.pagination}:{})})
})
app.get('/api/teachers/principal', async (req,res)=>{requireRole(req,adminRoles);const p=await one("SELECT id,full_name,email,status,joined_date FROM users WHERE role='principal' ORDER BY id LIMIT 1");assert(p,404,'Principal account not found.');res.json({data:p})})
app.put('/api/teachers/principal', async (req,res)=>{requireRole(req,adminRoles);const p=await one("SELECT id FROM users WHERE role='principal' ORDER BY id LIMIT 1");assert(p,404,'Principal account not found.');await query('UPDATE users SET full_name=?,email=? WHERE id=?',[req.body.full_name,req.body.email,p.id]);res.json({message:'Principal account updated.'})})
app.post('/api/teachers/principal/:action', async (req,res)=>{requireRole(req,adminRoles);const p=await one("SELECT id,status FROM users WHERE role='principal' ORDER BY id LIMIT 1");assert(p,404,'Principal account not found.');if(req.params.action==='toggle'){await query('UPDATE users SET status=? WHERE id=?',[p.status==='active'?'inactive':'active',p.id]);invalidateUserAuth(p.id)}else if(req.params.action==='reset-password')await query('UPDATE users SET password=? WHERE id=?',[await bcrypt.hash('password',10),p.id]);else fail(404,'Not found.');res.json({message:req.params.action==='reset-password'?'Principal account reset. Temporary password: password':'Principal account status updated.'})})
app.get('/api/teachers/:id/profile', async (req,res)=>{assert(adminRoles.includes(req.user.role)||Number(req.user.id)===Number(req.params.id),403);const teacher=await one("SELECT id,full_name,email,section,grade_level,status,joined_date,profile_image FROM users WHERE id=? AND role='teacher'",[req.params.id]);assert(teacher,404,'Teacher not found.');const [students,assessments,sessions]=await Promise.all([query('SELECT * FROM students WHERE teacher_id=? ORDER BY full_name',[req.params.id]),query('SELECT a.*,s.full_name FROM assessments a JOIN students s ON s.id=a.student_id WHERE s.teacher_id=? ORDER BY a.assessment_date DESC LIMIT 20',[req.params.id]),query('SELECT * FROM remedial_sessions WHERE teacher_id=? ORDER BY session_date DESC LIMIT 20',[req.params.id])]);res.json({teacher,students,assessments,sessions})})
app.post('/api/teachers', async (req,res)=>{requireRole(req,adminRoles);const b=req.body;assert(b.full_name&&b.email,422,'Name and email are required.');assert(!await one('SELECT id FROM users WHERE email=?',[b.email]),422,'Email is already in use.');const id=await insert("INSERT INTO users (full_name,email,section,grade_level,password,role,status,joined_date) VALUES (?,?,?,? ,?,'teacher','active',CURDATE())",[b.full_name,b.email,b.section||null,b.grade_level||null,await bcrypt.hash('password',10)]);res.status(201).json({message:'Teacher account created successfully!',id,temporary_password:'password'})})
app.put('/api/teachers/:id', async (req,res)=>{requireRole(req,adminRoles);const b=req.body;const teacher=await one("SELECT id FROM users WHERE id=? AND role='teacher'",[req.params.id]);assert(teacher,404,'Teacher not found.');assert(!await one('SELECT id FROM users WHERE email=? AND id<>?',[b.email,req.params.id]),422,'Email is already in use.');await query('UPDATE users SET full_name=?,email=?,section=?,grade_level=? WHERE id=?',[b.full_name,b.email,b.section||null,b.grade_level||null,req.params.id]);res.json({message:'Teacher profile updated.'})})
app.post('/api/teachers/:id/:action', async (req,res)=>{requireRole(req,adminRoles);const teacher=await one("SELECT id,status FROM users WHERE id=? AND role='teacher'",[req.params.id]);assert(teacher,404,'Teacher not found.');if(req.params.action==='toggle'){await query('UPDATE users SET status=? WHERE id=?',[teacher.status==='active'?'inactive':'active',teacher.id]);invalidateUserAuth(teacher.id)}else if(req.params.action==='reset-password')await query('UPDATE users SET password=? WHERE id=?',[await bcrypt.hash('password',10),teacher.id]);else if(req.params.action==='delete'){await query('DELETE FROM users WHERE id=?',[teacher.id]);invalidateUserAuth(teacher.id)}else fail(404,'Not found.');res.json({message:req.params.action==='reset-password'?'Teacher account reset. Temporary password: password':'Teacher account updated.'})})

app.get('/api/settings', async (_req,res)=>{const rows=await query('SELECT setting_key,setting_value FROM app_settings');res.json({settings:Object.fromEntries(rows.map(r=>[r.setting_key,r.setting_value]))})})
app.put('/api/settings', async (req,res)=>{requireRole(req,adminRoles);const keys=['school_name','school_id','school_address','school_contact','school_email','school_logo','school_year','program_name','grade_levels_covered','assessment_information'];await transaction(async c=>{for(const key of keys)await c.execute('INSERT INTO app_settings (setting_key,setting_value,updated_by,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=NOW()',[key,req.body[key]||'',req.user.id])});res.json({message:'School and reading program settings saved.'})})

app.get('/api/reports', async (req,res)=>{
  const teacher=req.user.role==='teacher';const params=[];let where=' WHERE 1=1';if(teacher){where+=' AND r.generated_by=?';params.push(req.user.id)}if(req.query.search){const q=searchLike(req.query.search);where+=' AND (r.report_title LIKE ? OR r.report_type LIKE ? OR r.status LIKE ?)';params.push(q,q,q)}
  const sql=`SELECT r.*,u.full_name generator_name FROM reports r LEFT JOIN users u ON u.id=r.generated_by${where} ORDER BY r.created_at DESC,r.id DESC`;const countSql=`SELECT COUNT(*) AS total FROM reports r${where}`;const page=await paginated(req,sql,params,countSql);const data=page?.data||await query(sql,params)
  const [total,avg,assessed]=await Promise.all([one(`SELECT COUNT(*) n FROM reports r${where}`,params),one(`SELECT AVG(score) average_score FROM students WHERE score>0${teacher?' AND teacher_id=?':''}`,teacher?[req.user.id]:[]),one(`SELECT COUNT(*) n FROM assessments a JOIN students s ON s.id=a.student_id${teacher?' WHERE s.teacher_id=?':''}`,teacher?[req.user.id]:[])])
  res.json({data,total_reports:Number(total.n),average_score:Number(avg.average_score||0),total_assessed:Number(assessed.n),...(page?{pagination:page.pagination}:{})})
})
app.post('/api/reports', async (req,res)=>{
  const b=req.body;assert(['activity','progress','attendance'].includes(b.report_type),422,'Choose a report type.');validateDate(b.start_date,'start_date');validateDate(b.end_date,'end_date');assert(b.end_date>=b.start_date,422,'End date must be on or after start date.')
  const type=b.report_type,start=b.start_date,end=b.end_date,teacher=req.user.role==='teacher',uid=req.user.id;let headers=[],rows=[]
  if(type==='progress'){
    const params=[start,end];let scope='';if(teacher){scope=' AND s.teacher_id=?';params.push(uid)}
    const data=await query(`SELECT s.full_name,s.grade_level,s.section,a.tool,a.total_score,a.profile,a.assessment_date FROM assessments a JOIN students s ON s.id=a.student_id WHERE a.assessment_date BETWEEN ? AND ?${scope} ORDER BY a.assessment_date DESC,s.full_name`,params);rows=data.map(r=>[r.full_name,`${r.grade_level} - ${r.section}`,r.tool,r.total_score,r.profile,r.assessment_date]);headers=['Pupil','Grade / Section','Assessment','Score','Reading Level','Date']
  }else if(type==='attendance'){
    const params=[start,end];let scope='';if(teacher){scope=' AND s.teacher_id=?';params.push(uid)}
    const data=await query(`SELECT s.full_name,s.grade_level,s.section,a.status,a.remarks,a.attendance_date FROM remedial_attendance a JOIN students s ON s.id=a.student_id WHERE a.attendance_date BETWEEN ? AND ?${scope} ORDER BY a.attendance_date DESC,s.full_name`,params);rows=data.map(r=>[r.full_name,`${r.grade_level} - ${r.section}`,r.status,r.remarks||'-',r.attendance_date]);headers=['Pupil','Grade / Section','Status','Remarks','Date']
  }else{
    const defs=[['Assessments','assessments','assessment_date','recorded_by'],['Progress updates','progress_updates','update_date','updated_by'],['Attendance records','remedial_attendance','attendance_date','recorded_by'],['Remedial sessions','remedial_sessions','session_date','teacher_id'],['Endorsements','endorsements','created_at','teacher_id'],['Materials distributed','material_distribution','date_given','given_by']]
    for(const [label,table,col,owner] of defs){const args=[start,end];const dateWhere=table==='endorsements'?`${col} >= ? AND ${col} < DATE_ADD(?,INTERVAL 1 DAY)`:`${col} BETWEEN ? AND ?`;let scope='';if(teacher){scope=` AND ${owner}=?`;args.push(uid)}const r=await one(`SELECT COUNT(*) n FROM ${table} WHERE ${dateWhere}${scope}`,args);rows.push([label,Number(r.n)])}headers=['Activity','Total']
  }
  const titles={activity:'Teacher Activity Report',progress:'Pupil Progress Report',attendance:'Attendance Report'};const title=`${titles[type]} (${start} to ${end})`;const esc=v=>String(v??'-').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));let html=`<!doctype html><html><head><meta charset="utf-8"><title>${esc(title)}</title><style>body{font-family:Arial,sans-serif;color:#1e293b;margin:40px}table{border-collapse:collapse;width:100%;margin-top:24px}th,td{border:1px solid #cbd5e1;padding:10px;text-align:left}th{background:#e2e8f0}</style></head><body><h1>${esc(title)}</h1><p>Generated on ${new Date().toLocaleString('en-PH')}</p><table><thead><tr>${headers.map(h=>`<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.length?rows.map(r=>`<tr>${r.map(v=>`<td>${esc(v)}</td>`).join('')}</tr>`).join(''):`<tr><td colspan="${headers.length}">No activity found for this period.</td></tr>`}</tbody></table></body></html>`
  const saved=await saveUpload({buffer:Buffer.from(html),originalname:'report.html',mimetype:'text/html',size:Buffer.byteLength(html)},'reports',`report_${uid}`);const id=await insert("INSERT INTO reports (report_title,report_type,generated_by,generated_date,file_format,status,file_path,created_at) VALUES (?,?,?,NOW(),'HTML','Ready',?,NOW())",[title,type,uid,saved.relative]);res.status(201).json({message:'Report generated successfully.',data:await one('SELECT * FROM reports WHERE id=?',[id])})
})
app.get('/api/reports/:id/download', async (req,res)=>{const p=[];let where=' WHERE id=?';p.push(req.params.id);if(req.user.role==='teacher'){where+=' AND generated_by=?';p.push(req.user.id)}const r=await one(`SELECT file_path FROM reports${where}`,p);assert(r?.file_path,404,'Report not found.');res.type('html');sendFile(res,r.file_path,true)})

app.use((error, req, res, _next) => {
  if (res.headersSent) return
  const status=Number(error.status||error.statusCode||0)
  if (String(error.code || '').startsWith('LIMIT_')) return res.status(413).json({message:'The uploaded file is too large.'})
  if (error.code==='ER_DUP_ENTRY') return res.status(422).json({message:'A record with the same unique value already exists.'})
  if (error.code==='ER_NO_SUCH_TABLE'||error.code==='ER_BAD_FIELD_ERROR') return res.status(503).json({message:'Database schema is missing or outdated.',detail:process.env.APP_DEBUG==='true'?error.message:undefined})
  if (status) return res.status(status).json({message:error.message||'Request could not be completed.',...(error.errors?{errors:error.errors}:{})})
  console.error(JSON.stringify({ event: 'api_error', method: req.method, path: req.path, code: error.code || null, message: error.message }))
  console.error(error.stack)
  return res.status(500).json({message:process.env.APP_DEBUG==='true'?error.message:'Server error.'})
})

await fs.mkdir(uploadsRoot,{recursive:true})
app.listen(port,'0.0.0.0',()=>console.log(`Subaybay Node API listening on ${port}`))
process.on('SIGTERM',async()=>{await pool.end();process.exit(0)})




