import fs from 'node:fs'
import mysql from 'mysql2/promise'

const host = process.env.DB_HOST || '127.0.0.1'
const caPath = process.env.MYSQL_ATTR_SSL_CA
const tlsRequired = process.env.DB_SSL === 'true' || /tidbcloud\.com$/i.test(host)

export const pool = mysql.createPool({
  host,
  port: Number(process.env.DB_PORT || 3306),
  user: process.env.DB_USERNAME || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_DATABASE || 'subaybay',
  waitForConnections: true,
  connectionLimit: Number(process.env.DB_CONNECTION_LIMIT || 10),
  maxIdle: 5,
  idleTimeout: 60000,
  queueLimit: 0,
  dateStrings: true,
  enableKeepAlive: true,
  ...(tlsRequired ? { ssl: caPath ? { ca: fs.readFileSync(caPath), rejectUnauthorized: true } : { rejectUnauthorized: true } } : {}),
})

export async function query(sql, params = []) {
  const [rows] = await pool.execute(sql, params)
  return rows
}

export async function one(sql, params = []) {
  const rows = await query(sql, params)
  return rows[0] || null
}

export async function insert(sql, params = []) {
  const [result] = await pool.execute(sql, params)
  return result.insertId
}

export async function transaction(work) {
  const connection = await pool.getConnection()
  try {
    await connection.beginTransaction()
    const result = await work(connection)
    await connection.commit()
    return result
  } catch (error) {
    await connection.rollback()
    throw error
  } finally {
    connection.release()
  }
}
