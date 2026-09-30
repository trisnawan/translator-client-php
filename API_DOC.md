# Translator Backend — Dokumentasi API

REST API penerjemah multi-bahasa (**NestJS + MySQL + RabbitMQ**) dengan dua kelompok pengguna:

- **Admin** — mengelola akun, bahasa, driver, akses driver, API key, dan memantau seluruh riwayat translasi.
- **Client** — mengirim permintaan terjemahan (`POST /translate`), mengelola API key sendiri, dan membaca riwayat miliknya.

Semua request dan response berformat **JSON**. Terjemahan diproses **asynchronous** oleh worker: `POST /translate` langsung dibalas `200 OK`, lalu hasilnya dikirim ke `callback_url` client atau diambil dengan polling `/histories`.

## Daftar Isi

1. [Gambaran Umum](#1-gambaran-umum)
2. [Autentikasi](#2-autentikasi) — termasuk contoh **PHP**, **JavaScript/TypeScript**, dan **Go**
3. [Ringkasan Endpoint per Role](#3-ringkasan-endpoint-per-role)
4. [Endpoint Bersama (Admin & Client)](#4-endpoint-bersama-admin--client)
5. [Endpoint Khusus Admin](#5-endpoint-khusus-admin)
6. [Callback ke Client](#6-callback-ke-client)
7. [Error & Status Code](#7-error--status-code)

---

## 1. Gambaran Umum

| Item           | Nilai                                                                 |
| -------------- | --------------------------------------------------------------------- |
| Base URL       | `http://localhost:3000` (lihat `APP_URL` / `APP_PORT`)                |
| Prefix versi   | tidak ada — semua path di root                                        |
| Content-Type   | `application/json` (body limit default `1mb`, `BODY_LIMIT`)           |
| Format tanggal | ISO 8601 UTC, mis. `2026-09-30T02:30:00.000Z`                         |
| Format `{ID}`  | UUIDv7 bentuk teks untuk account/key/history, driver `id` berupa slug |
| Bahasa `{ID}`  | kode ISO 639-1 dua huruf (`id`, `en`)                                 |

> Field body di luar kontrak **ditolak** dengan `400 Validation failed` (whitelist ketat), sehingga typo seperti `driverId` (seharusnya `driver_id`) tidak lolos diam-diam.

### 1.1 Format Response Sukses

```json
{
  "success": true,
  "message": "Languages retrieved successfully",
  "data": [
    { "id": "id", "name": "Bahasa Indonesia", "status": "active" },
    { "id": "en", "name": "English", "status": "active" }
  ],
  "meta": {
    "page": 1,
    "limit": 10,
    "total": 2,
    "totalPages": 1,
    "hasNext": false,
    "hasPrevious": false
  }
}
```

`meta` hanya muncul pada endpoint list. Endpoint yang tidak mengembalikan data (mis. `DELETE`) memakai `"data": null`.

### 1.2 Format Response Error

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": [{ "message": "driver_id is required" }],
  "meta": {
    "path": "/translate",
    "method": "POST",
    "statusCode": 400,
    "timestamp": "2026-09-30T02:30:00.000Z"
  }
}
```

### 1.3 Kode Status HTTP

| Code | Arti                                                                |
| ---- | ------------------------------------------------------------------- |
| 200  | sukses (`GET`, `PUT`, `DELETE`, dan `POST /translate`)              |
| 201  | sukses membuat data (`POST` selain `/translate`)                    |
| 400  | validasi input / data tidak valid / master data tidak aktif         |
| 401  | token tidak ada/salah/kedaluwarsa (`key_id` & signature token juga) |
| 403  | akun nonaktif, role tidak sesuai, driver tidak diberikan ke akun    |
| 404  | data tidak ditemukan                                                |
| 409  | konflik data (duplikat, masih dipakai, hapus akun sendiri)          |
| 429  | quota akun `max_rpm` / `max_rpd` terlampaui                         |
| 503  | broker / engine / driver belum siap                                 |

### 1.4 Parameter List (Query) yang Umum

| Parameter | Default | Keterangan                                                     |
| --------- | ------- | -------------------------------------------------------------- |
| `page`    | `1`     | halaman, mulai dari 1                                          |
| `limit`   | `10`    | jumlah baris per halaman (maks 100)                            |
| `search`  | –       | keyword; kolom yang dicari berbeda per resource (lihat detail) |
| `order`   | `desc`  | arah urut `asc` / `desc` berdasarkan tanggal dibuat            |

Filter tambahan dipakai oleh resource tertentu: `status`, `type`, `role`, `driver_id`, `account_id`, `translate_from`, `translate_to`, `reference_id`, `date_from`, `date_to`, `callback_status`.

---

## 2. Autentikasi

Ada **dua jenis token JWT HS256** yang dipakai aplikasi ini:

| Jenis               | Ditandatangani dengan                    | Dipakai untuk                                                                     |
| ------------------- | ---------------------------------------- | --------------------------------------------------------------------------------- |
| **Access token**    | `JWT_SECRET` (server)                    | Semua endpoint admin/client (kecuali `/`, `/health`, `/auth/login`, `/translate`) |
| **Signature token** | `account_keys.secret_key` (milik client) | `POST /translate` dan verifikasi `callback` dari server ke client                 |

Ringkasnya:

- **Dashboard admin / panel client (manusia)** → login, pakai **access token** (header `Authorization: Bearer` atau cookie `access_token`).
- **Integrasi mesin-ke-mesin** → cukup punya `key_id` + `secret_key` akun, tidak perlu login. Kirim `key_id` + `Authorization: Bearer <signature token>` ke `POST /translate`.

### 2.1 Access Token (Admin & Client)

Login mengembalikan token sekaligus mengeset cookie `access_token` (httpOnly, `sameSite=lax`, `secure` saat production) sehingga frontend browser tidak perlu menyimpan token di `localStorage`.

Payload JWT:

```json
{
  "sub": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
  "email": "devs.trisnasejati@gmail.com",
  "role": "client",
  "full_name": "Client",
  "iat": 1780272000,
  "exp": 1780358400
}
```

Kirim token pada setiap request melalui salah satu cara (keduanya ekuivalen):

```http
Authorization: Bearer <access_token>
```

atau cookie `access_token` (otomatis pada frontend browser dengan `credentials: 'include'`).

Masa berlaku default `expires_in`: `86400` detik (`JWT_EXPIRES_IN`).

### 2.2 Signature Token (`POST /translate` & callback)

`POST /translate` **tidak memakai access token**, melainkan skema kredensial API key:

1. Client mengirim header `key_id` berisi `id` dari `account_keys`.
2. Server mengambil `secret_key` milik key tersebut (terenkripsi di database).
3. Client menambahkan header `Authorization: Bearer <JWT>` yang **ditandatangani dengan `secret_key`** (HMAC-SHA256), payload:

```json
{
  "account_id": "<UUID akun pemilik key>",
  "reference_id": "<reference_id yang sama dengan body request>",
  "iat": 1780272000,
  "exp": 1780272300
}
```

4. Server memverifikasi: `key_id` ada → `secret_key` cocok → `account_id` sesuai pemilik key → `reference_id` token sama dengan body request → akun masih aktif.

Konsekuensinya token hanya berlaku untuk satu `reference_id` tertentu (tidak bisa di-replay untuk payload lain). Masa berlaku default: `300` detik (`SIGNATURE_TOKEN_TTL`).

Header lengkap `POST /translate`:

```http
Content-Type: application/json
key_id: <account_key_id>
Authorization: Bearer <signature_token>
```

> Skema yang sama dipakai server ketika mengirim callback ke client: header `key_id` + `Authorization` ditandatangani dengan secret key yang sama, sehingga **client dapat memverifikasi keaslian callback**. Lihat [bagian 6](#6-callback-ke-client).

---

### 2.3 Contoh Autentikasi — PHP

```php
<?php
declare(strict_types=1);

const BASE_URL = 'http://localhost:3000';

/** Helper request JSON, sekaligus membaca envelope { success, message, data }. */
function apiRequest(string $method, string $path, array $body = [], array $headers = []): array
{
    $ch = curl_init(BASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS     => $body === [] ? null : json_encode($body),
        CURLOPT_TIMEOUT        => 30,
    ]);

    $raw    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'json' => json_decode((string) $raw, true)];
}

// ---------- 1) Login & pakai access token ----------

$login = apiRequest('POST', '/auth/login', [
    'email'    => 'devs.trisnasejati@gmail.com',
    'password' => 'client123',
]);

$accessToken = $login['json']['data']['access_token'];
$accountId   = $login['json']['data']['account']['id'];

$histories = apiRequest('GET', '/histories?page=1&limit=10', [], [
    'Authorization: Bearer ' . $accessToken,
]);

// ---------- 2) Signature token untuk POST /translate ----------

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function signSignatureToken(string $secretKey, string $accountId, string $referenceId, int $ttl = 300): string
{
    $now       = time();
    $header    = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload   = base64UrlEncode(json_encode([
        'account_id'   => $accountId,
        'reference_id' => $referenceId,
        'iat'          => $now,
        'exp'          => $now + $ttl,
    ]));
    $signature = base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $secretKey, true));

    return $header . '.' . $payload . '.' . $signature;
}

$keyId       = '<KEY_ID>';          // id account_keys
$secretKey   = 'sk_translator_...'; // hanya diketahui client
$referenceId = 'INV-2026-0001';

$translate = apiRequest('POST', '/translate', [
    'driver_id'         => 'gemini-3.8-flash',
    'translate_from'    => 'id',
    'translate_to'      => 'en',
    'reference_id'      => $referenceId,
    'reference_content' => 'Selamat pagi, apa kabar?',
], [
    'key_id: ' . $keyId,
    'Authorization: Bearer ' . signSignatureToken($secretKey, $accountId, $referenceId),
]);

echo $translate['json']['data']['history_id'];
```

Alternatif: library `firebase/php-jwt`

```php
use Firebase\JWT\JWT;

$token = JWT::encode([
    'account_id'   => $accountId,
    'reference_id' => $referenceId,
    'iat'          => time(),
    'exp'          => time() + 300,
], $secretKey, 'HS256');
```

---

### 2.4 Contoh Autentikasi — JavaScript / TypeScript

```ts
import { createHmac } from 'node:crypto';

const BASE_URL = 'http://localhost:3000';

/** Helper request JSON + envelope parsing. */
async function api<T = unknown>(
  method: string,
  path: string,
  body?: unknown,
  headers: Record<string, string> = {},
): Promise<T> {
  const response = await fetch(`${BASE_URL}${path}`, {
    method,
    headers: { 'Content-Type': 'application/json', ...headers },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const json = await response.json();

  if (!response.ok) {
    throw new Error(`${response.status}: ${json.message}`);
  }

  return json.data as T;
}

// ---------- 1) Login & pakai access token ----------

const login = await api<{
  access_token: string;
  account: { id: string };
}>('POST', '/auth/login', {
  email: 'devs.trisnasejati@gmail.com',
  password: 'client123',
});

const accessToken = login.access_token;
const accountId = login.account.id;

const histories = await api('GET', '/histories?page=1&limit=10', undefined, {
  Authorization: `Bearer ${accessToken}`,
});

// ---------- 2) Signature token untuk POST /translate ----------

function signSignatureToken(
  secretKey: string,
  accountId: string,
  referenceId: string,
  ttlSeconds = 300,
): string {
  const now = Math.floor(Date.now() / 1000);
  const encode = (value: object) =>
    Buffer.from(JSON.stringify(value)).toString('base64url');

  const header = encode({ alg: 'HS256', typ: 'JWT' });
  const payload = encode({
    account_id: accountId,
    reference_id: referenceId,
    iat: now,
    exp: now + ttlSeconds,
  });
  const signature = createHmac('sha256', secretKey)
    .update(`${header}.${payload}`)
    .digest('base64url');

  return `${header}.${payload}.${signature}`;
}

const keyId = '<KEY_ID>'; // id account_keys
const secretKey = 'sk_translator_...'; // hanya diketahui client
const referenceId = 'INV-2026-0001';

const translate = await api<{ history_id: string }>(
  'POST',
  '/translate',
  {
    driver_id: 'gemini-3.8-flash',
    translate_from: 'id',
    translate_to: 'en',
    reference_id: referenceId,
    reference_content: 'Selamat pagi, apa kabar?',
  },
  {
    key_id: keyId,
    Authorization: `Bearer ${signSignatureToken(secretKey, accountId, referenceId)}`,
  },
);

console.log(translate.history_id);
```

Catatan:

- Di **browser**, kirim `credentials: 'include'` agar cookie `access_token` ikut terkirim; `Buffer` tidak tersedia, gunakan `btoa` + `TextEncoder` atau library `jose` / `jsonwebtoken`.
- Alternatif dengan library: `jwt.sign({ account_id, reference_id }, secretKey, { algorithm: 'HS256', expiresIn: 300 })`.
- Callback receiver contoh ada di repo: `npm run callback:listen` (port 4000) — lihat [bagian 6](#6-callback-ke-client).

---

### 2.5 Contoh Autentikasi — Go

```go
package main

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"
)

const baseURL = "http://localhost:3000"

type envelope struct {
	Success bool            `json:"success"`
	Message string          `json:"message"`
	Data    json.RawMessage `json:"data"`
}

// api mengirim request JSON dan mengembalikan envelope standar.
func api(method, path, token string, body any, extraHeaders map[string]string) (*envelope, error) {
	var reader io.Reader
	if body != nil {
		payload, err := json.Marshal(body)
		if err != nil {
			return nil, err
		}
		reader = bytes.NewReader(payload)
	}

	req, err := http.NewRequest(method, baseURL+path, reader)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	for key, value := range extraHeaders {
		req.Header.Set(key, value)
	}

	res, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()

	raw, err := io.ReadAll(res.Body)
	if err != nil {
		return nil, err
	}

	var out envelope
	if err := json.Unmarshal(raw, &out); err != nil {
		return nil, fmt.Errorf("decode response: %w", err)
	}
	if res.StatusCode >= 400 {
		return nil, fmt.Errorf("%d: %s", res.StatusCode, out.Message)
	}

	return &out, nil
}

// signSignatureToken membuat JWT HS256 dengan payload { account_id, reference_id }.
func signSignatureToken(secretKey, accountID, referenceID string, ttl time.Duration) string {
	now := time.Now().Unix()
	encode := func(value any) string {
		data, _ := json.Marshal(value)
		return base64.RawURLEncoding.EncodeToString(data)
	}

	header := encode(map[string]string{"alg": "HS256", "typ": "JWT"})
	payload := encode(map[string]any{
		"account_id":   accountID,
		"reference_id": referenceID,
		"iat":          now,
		"exp":          now + int64(ttl.Seconds()),
	})

	mac := hmac.New(sha256.New, []byte(secretKey))
	mac.Write([]byte(header + "." + payload))

	return header + "." + payload + "." + base64.RawURLEncoding.EncodeToString(mac.Sum(nil))
}

func main() {
	// ---------- 1) Login & pakai access token ----------
	login, err := api("POST", "/auth/login", "", map[string]string{
		"email":    "devs.trisnasejati@gmail.com",
		"password": "client123",
	}, nil)
	if err != nil {
		panic(err)
	}

	var loginData struct {
		AccessToken string `json:"access_token"`
		Account     struct {
			ID string `json:"id"`
		} `json:"account"`
	}
	if err := json.Unmarshal(login.Data, &loginData); err != nil {
		panic(err)
	}

	histories, err := api("GET", "/histories?page=1&limit=10", loginData.AccessToken, nil, nil)
	if err != nil {
		panic(err)
	}
	fmt.Println(string(histories.Data))

	// ---------- 2) Signature token untuk POST /translate ----------
	const (
		keyID       = "<KEY_ID>"            // id account_keys
		secretKey   = "sk_translator_..."   // hanya diketahui client
		referenceID = "INV-2026-0001"
	)

	signature := signSignatureToken(secretKey, loginData.Account.ID, referenceID, 5*time.Minute)

	translate, err := api("POST", "/translate", "", map[string]string{
		"driver_id":         "gemini-3.8-flash",
		"translate_from":    "id",
		"translate_to":      "en",
		"reference_id":      referenceID,
		"reference_content": "Selamat pagi, apa kabar?",
	}, map[string]string{
		"key_id":        keyID,
		"Authorization": "Bearer " + signature,
	})
	if err != nil {
		panic(err)
	}

	fmt.Println(string(translate.Data))
}
```

Alternatif: library `github.com/golang-jwt/jwt/v5`

```go
token := jwt.NewWithClaims(jwt.SigningMethodHS256, jwt.MapClaims{
	"account_id":   accountID,
	"reference_id": referenceID,
	"iat":          time.Now().Unix(),
	"exp":          time.Now().Add(5 * time.Minute).Unix(),
})
signed, _ := token.SignedString([]byte(secretKey))
```

---

## 3. Ringkasan Endpoint per Role

### 3.1 Role Admin

| Method | Endpoint                          | Fungsi                                        |
| ------ | --------------------------------- | --------------------------------------------- |
| POST   | `/auth/login`                     | login (publik)                                |
| GET    | `/access-token`                   | perbarui access token                         |
| GET    | `/auth/me`                        | profil sendiri                                |
| POST   | `/auth/logout`                    | logout                                        |
| GET    | `/languages`                      | daftar bahasa                                 |
| POST   | `/languages/insert`               | tambah bahasa                                 |
| PUT    | `/languages/update/{ID}`          | ubah bahasa                                   |
| GET    | `/drivers`                        | daftar semua driver                           |
| POST   | `/drivers/insert`                 | tambah driver                                 |
| PUT    | `/drivers/update/{ID}`            | ubah driver                                   |
| DELETE | `/drivers/delete/{ID}`            | hapus driver                                  |
| GET    | `/accounts`                       | daftar semua akun                             |
| GET    | `/accounts/detail/{ID}`           | detail akun                                   |
| POST   | `/accounts/insert`                | tambah akun                                   |
| PUT    | `/accounts/update/{ID}`           | ubah akun                                     |
| DELETE | `/accounts/delete/{ID}`           | hapus akun                                    |
| GET    | `/account-drivers`                | daftar akses driver per akun                  |
| POST   | `/account-drivers/insert`         | beri akses driver                             |
| PUT    | `/account-drivers/update/{ID}`    | ubah akses driver                             |
| DELETE | `/account-drivers/delete/{ID}`    | hapus akses driver                            |
| GET    | `/account-keys`                   | daftar semua API key (filter `account_id`)    |
| GET    | `/account-keys/detail/{ID}`       | detail API key                                |
| POST   | `/account-keys/insert`            | buat API key (boleh untuk akun lain)          |
| PUT    | `/account-keys/update/{ID}`       | ubah / rotasi API key                         |
| DELETE | `/account-keys/delete/{ID}`       | hapus API key                                 |
| GET    | `/histories`                      | daftar semua riwayat (filter `account_id`)    |
| GET    | `/histories/detail/{ID}`          | detail riwayat                                |
| POST   | `/histories/resend-callback/{ID}` | kirim ulang callback                          |
| POST   | `/histories/retranslate/{ID}`     | ulangi terjemahan                             |
| DELETE | `/histories/delete/{ID}`          | hapus riwayat                                 |
| POST   | `/translate`                      | kirim permintaan terjemahan (signature token) |

### 3.2 Role Client

| Method | Endpoint                          | Fungsi                                        |
| ------ | --------------------------------- | --------------------------------------------- |
| POST   | `/auth/login`                     | login (publik) — untuk panel client           |
| GET    | `/access-token`                   | perbarui access token                         |
| GET    | `/auth/me`                        | profil sendiri                                |
| POST   | `/auth/logout`                    | logout                                        |
| GET    | `/languages`                      | daftar bahasa aktif                           |
| GET    | `/drivers`                        | daftar driver yang **diberikan** ke akunnya   |
| GET    | `/account-keys`                   | daftar API key miliknya                       |
| GET    | `/account-keys/detail/{ID}`       | detail API key miliknya                       |
| POST   | `/account-keys/insert`            | buat API key miliknya                         |
| PUT    | `/account-keys/update/{ID}`       | ubah API key miliknya                         |
| DELETE | `/account-keys/delete/{ID}`       | hapus API key miliknya                        |
| GET    | `/histories`                      | daftar riwayat miliknya                       |
| GET    | `/histories/detail/{ID}`          | detail riwayat miliknya                       |
| POST   | `/histories/resend-callback/{ID}` | kirim ulang callback miliknya                 |
| POST   | `/translate`                      | kirim permintaan terjemahan (signature token) |

### 3.3 Role Publik

| Method | Endpoint      | Fungsi                       |
| ------ | ------------- | ---------------------------- |
| GET    | `/`           | informasi aplikasi           |
| GET    | `/health`     | status aplikasi + dependency |
| POST   | `/auth/login` | login admin/client           |

---

## 4. Endpoint Bersama (Admin & Client)

Semua request pada bagian ini memakai base URL `http://localhost:3000` dan header `Content-Type: application/json`.

### 4.1 Info Aplikasi

**Deskripsi:** Informasi nama, environment, versi, dan waktu server. Publik, tanpa token.

**Endpoint:** `GET /` — publik

**Request:**

```http
GET /
```

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Translator API is running",
  "data": {
    "name": "translator-backend",
    "description": "Multi language translation REST API",
    "environment": "development",
    "version": "1.0.0",
    "timezone": "Asia/Jakarta",
    "time": "2026-09-30T02:27:51.000Z",
    "documentation": "See README.md for the full API reference"
  }
}
```

### 4.2 Health Check

**Deskripsi:** Liveness probe sekaligus status koneksi database dan broker. Publik, tanpa token.

**Endpoint:** `GET /health` — publik

**Request:**

```http
GET /health
```

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Health check completed",
  "data": {
    "status": "ok",
    "app": "translator-backend",
    "environment": "development",
    "timezone": "Asia/Jakarta",
    "version": "1.0.0",
    "uptime": 42,
    "timestamp": "2026-09-30T02:27:51.000Z",
    "dependencies": { "database": "up", "broker": "up" }
  }
}
```

`status` bernilai `degraded` bila database/broker `down`; `broker` bernilai `disabled` bila `RABBITMQ_ENABLED=false`.

### 4.3 Login

**Deskripsi:** Autentikasi akun admin/client. Mengembalikan access token dan mengeset cookie `access_token` (httpOnly). Publik.

**Endpoint:** `POST /auth/login` — publik

**Request:**

```http
POST /auth/login
Content-Type: application/json

{
  "email": "devs.trisnasejati@gmail.com",
  "password": "client123"
}
```

| Field      | Tipe   | Wajib | Keterangan                      |
| ---------- | ------ | ----- | ------------------------------- |
| `email`    | string | ✅    | email akun (otomatis lowercase) |
| `password` | string | ✅    | minimal 6 karakter              |

**Response:** `201 Created`

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "token_type": "Bearer",
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIwMWEwZWVhMi0y...",
    "expires_in": 86400,
    "expires_at": "2026-10-01T02:29:36.000Z",
    "account": {
      "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
      "full_name": "Client",
      "email": "devs.trisnasejati@gmail.com",
      "role": "client",
      "status": "active",
      "max_rpm": 0,
      "max_rpd": 0
    }
  }
}
```

**Error:** `401` email/password salah, `403` akun `inactive`, `400` body tidak valid.

### 4.4 Perbarui Access Token

**Deskripsi:** Menerbitkan access token baru untuk sesi yang sedang aktif (refresh), sekaligus memperbarui cookie.

**Endpoint:** `GET /access-token` — admin, client

**Request:**

```http
GET /access-token
Authorization: Bearer <access_token>
```

**Response:** `200 OK` — struktur `data` sama dengan `POST /auth/login`, dengan `message: "Access token issued successfully"`.

```json
{
  "success": true,
  "message": "Access token issued successfully",
  "data": {
    "token_type": "Bearer",
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIwMWEwZWVhMi0y...",
    "expires_in": 86400,
    "expires_at": "2026-10-01T03:00:00.000Z",
    "account": {
      "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
      "full_name": "Client",
      "email": "devs.trisnasejati@gmail.com",
      "role": "client",
      "status": "active",
      "max_rpm": 0,
      "max_rpd": 0
    }
  }
}
```

### 4.5 Profil Akun

**Deskripsi:** Data akun yang sedang login.

**Endpoint:** `GET /auth/me` — admin, client

**Request:**

```http
GET /auth/me
Authorization: Bearer <access_token>
```

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Profile retrieved successfully",
  "data": {
    "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
    "full_name": "Client",
    "email": "devs.trisnasejati@gmail.com",
    "role": "client",
    "status": "active",
    "max_rpm": 0,
    "max_rpd": 0,
    "created_at": "2026-09-29T19:26:38.000Z",
    "updated_at": "2026-09-29T19:26:38.000Z"
  }
}
```

### 4.6 Logout

**Deskripsi:** Menghapus cookie `access_token`. Token tetap valid sampai kedaluwarsa (stateless JWT) — hapus juga salinannya di sisi client.

**Endpoint:** `POST /auth/logout` — admin, client

**Request:**

```http
POST /auth/logout
Authorization: Bearer <access_token>
```

**Response:** `201 Created`

```json
{
  "success": true,
  "message": "Logout successful",
  "data": null
}
```

### 4.7 Daftar Bahasa

**Deskripsi:** Daftar bahasa yang terdaftar. Dipakai untuk mengisi pilihan bahasa pada form terjemahan.

**Endpoint:** `GET /languages` — admin, client

**Request:**

```http
GET /languages?page=1&limit=10&search=english&status=active&order=asc
Authorization: Bearer <access_token>
```

| Query    | Default | Keterangan                  |
| -------- | ------- | --------------------------- |
| `search` | –       | dicari pada `id` dan `name` |
| `status` | –       | `active` / `inactive`       |

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Languages retrieved successfully",
  "data": [
    { "id": "id", "name": "Bahasa Indonesia", "status": "active" },
    { "id": "en", "name": "English", "status": "active" }
  ],
  "meta": {
    "page": 1,
    "limit": 10,
    "total": 2,
    "totalPages": 1,
    "hasNext": false,
    "hasPrevious": false
  }
}
```

### 4.8 Kirim Permintaan Terjemahan

**Deskripsi:** Menerima job terjemahan lalu memprosesnya di background (worker). Balasan berisi `history_id` untuk polling dan penanda apakah callback aktif. **Tidak memakai access token**, melainkan `key_id` + signature token (lihat [bagian 2.2](#22-signature-token-post-translate--callback)).

**Endpoint:** `POST /translate` — admin, client (autentikasi API key)

**Request:**

```http
POST /translate
Content-Type: application/json
key_id: 01a0eea2-2296-71ae-bd0b-9bee0cd7106f
Authorization: Bearer <signature_token>

{
  "driver_id": "gemini-3.8-flash",
  "translate_from": "id",
  "translate_to": "en",
  "reference_id": "INV-2026-0001",
  "reference_content": "Selamat pagi, apa kabar?"
}
```

| Field               | Tipe        | Wajib | Keterangan                                                               |
| ------------------- | ----------- | ----- | ------------------------------------------------------------------------ |
| `driver_id`         | string(20)  | ✅    | id driver yang diberikan ke akun (mis. `gemini-3.8-flash`)               |
| `translate_from`    | string(2)   | ✅    | kode bahasa sumber                                                       |
| `translate_to`      | string(2)   | ✅    | kode bahasa tujuan                                                       |
| `reference_id`      | string(100) | ✅    | id milik client; **harus sama** dengan `reference_id` di signature token |
| `reference_content` | string      | ✅    | konten yang diterjemahkan (maks `TRANSLATION_MAX_CHARS`, default 5000)   |

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Translation request accepted",
  "data": {
    "history_id": "01a0eea4-dc8a-7a15-b4e1-7ad72ade53d4",
    "reference_id": "INV-2026-0001",
    "driver_id": "gemini-3.8-flash",
    "translate_from": "id",
    "translate_to": "en",
    "status": "requested",
    "requested_at": "2026-09-29T19:29:36.907Z",
    "callback_enabled": true
  }
}
```

`callback_enabled: false` berarti `account_keys.callback_url` kosong — ambil hasilnya dengan polling `GET /histories/detail/{ID}`.

> Bila `translate_from` sama dengan `translate_to`, konten dianggap sudah benar: job langsung `translated` tanpa memanggil provider (menghemat quota).

**Error:** `400` field tidak valid / bahasa nonaktif / driver nonaktif / konten melebihi batas, `401` `key_id` atau signature token salah/kedaluwarsa, `403` akun nonaktif atau driver tidak diberikan ke akun, `404` driver tidak ditemukan, `429` quota akun terlampaui, `503` driver belum punya `secret_key` atau broker tidak tersedia.

### 4.9 Daftar Driver

**Deskripsi:** Admin melihat semua driver; client hanya melihat driver yang diberikan melalui `account_drivers`. `secret_key` tidak pernah dikembalikan.

**Endpoint:** `GET /drivers` — admin, client

**Request:**

```http
GET /drivers?page=1&limit=10&status=active&type=ai&search=gemini
Authorization: Bearer <access_token>
```

| Query    | Default | Keterangan                  |
| -------- | ------- | --------------------------- |
| `search` | –       | dicari pada `id` dan `name` |
| `status` | –       | `active` / `inactive`       |
| `type`   | –       | `ai` / `api`                |

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Drivers retrieved successfully",
  "data": [
    {
      "id": "gemini-3.8-flash",
      "type": "ai",
      "name": "Gemini Flash",
      "status": "active",
      "max_rpm": 10,
      "max_rpd": 250,
      "has_secret_key": true,
      "engine": "gemini"
    }
  ],
  "meta": {
    "page": 1,
    "limit": 10,
    "total": 1,
    "totalPages": 1,
    "hasNext": false,
    "hasPrevious": false
  }
}
```

`engine` adalah engine yang mengeksekusi driver (`gemini`, `claude`, `deepseek`, `google-translate`, atau `null` bila prefix id tidak dikenal).

### 4.10 Daftar API Key

**Deskripsi:** Daftar API key. Admin melihat semua key (boleh filter `account_id`); client selalu dibatasi ke key miliknya. `secret_key` hanya ditampilkan dalam bentuk mask.

**Endpoint:** `GET /account-keys` — admin, client

**Request:**

```http
GET /account-keys?page=1&limit=10&search=devs
Authorization: Bearer <access_token>
```

| Query        | Default | Keterangan                                   |
| ------------ | ------- | -------------------------------------------- |
| `search`     | –       | dicari pada nama/email akun dan callback URL |
| `account_id` | –       | **admin saja**, filter pemilik key           |

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Account keys retrieved successfully",
  "data": [
    {
      "id": "01a0eea2-2296-71ae-bd0b-9bee0cd7106f",
      "account_id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
      "account": {
        "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
        "full_name": "Client",
        "email": "devs.trisnasejati@gmail.com"
      },
      "callback_url": "http://localhost:4000/callback",
      "secret_key_masked": "********",
      "has_secret_key": true,
      "created_at": "2026-09-29T19:26:38.000Z"
    }
  ],
  "meta": {
    "page": 1,
    "limit": 10,
    "total": 1,
    "totalPages": 1,
    "hasNext": false,
    "hasPrevious": false
  }
}
```

### 4.11 Detail API Key

**Deskripsi:** Detail satu API key. Client hanya boleh mengakses key miliknya (`403` bila bukan).

**Endpoint:** `GET /account-keys/detail/{ID}` — admin, client

**Request:**

```http
GET /account-keys/detail/01a0eea2-2296-71ae-bd0b-9bee0cd7106f
Authorization: Bearer <access_token>
```

**Response:** `200 OK` — struktur `data` sama dengan item pada `GET /account-keys`.

```json
{
  "success": true,
  "message": "Account key retrieved successfully",
  "data": {
    "id": "01a0eea2-2296-71ae-bd0b-9bee0cd7106f",
    "account_id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
    "account": {
      "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
      "full_name": "Client",
      "email": "devs.trisnasejati@gmail.com"
    },
    "callback_url": "http://localhost:4000/callback",
    "secret_key_masked": "********",
    "has_secret_key": true,
    "created_at": "2026-09-29T19:26:38.000Z"
  }
}
```

### 4.12 Buat API Key

**Deskripsi:** Membuat API key baru. **`secret_key` hanya dikembalikan satu kali di response ini** — simpan segera (password manager / secret store). Bila `secret_key` tidak diisi, server membuat `sk_translator_<64 hex>`.

**Endpoint:** `POST /account-keys/insert` — admin, client

**Request:**

```http
POST /account-keys/insert
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "account_id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
  "secret_key": "sk_translator_rahasia_client_saya",
  "callback_url": "http://localhost:4000/callback"
}
```

| Field          | Tipe           | Wajib | Keterangan                                                                   |
| -------------- | -------------- | ----- | ---------------------------------------------------------------------------- |
| `account_id`   | UUID           | –     | admin boleh menentukan akun lain; client selalu diarahkan ke akunnya sendiri |
| `secret_key`   | string(16-255) | –     | bila kosong server membuat `sk_translator_<64 hex>`                          |
| `callback_url` | URL(500)       | –     | tujuan callback; boleh dikosongkan bila client ingin polling `/histories`    |

**Response:** `201 Created`

```json
{
  "success": true,
  "message": "Account key created successfully",
  "data": {
    "id": "01a0eea2-2296-71ae-bd0b-9bee0cd7106f",
    "account_id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
    "account": {
      "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
      "full_name": "Client",
      "email": "devs.trisnasejati@gmail.com"
    },
    "callback_url": "http://localhost:4000/callback",
    "secret_key_masked": "********",
    "has_secret_key": true,
    "created_at": "2026-09-30T02:30:00.000Z",
    "secret_key": "sk_translator_3f9a2c7b5d1e8f4a6b0c9d2e5f7a1b3c8d4e6f0a2b4c6d8e0f1a3b5c7d9e1f3b"
  }
}
```

### 4.13 Ubah API Key

**Deskripsi:** Mengubah `callback_url` dan/atau merotasi `secret_key`. Client hanya boleh mengubah key miliknya.

**Endpoint:** `PUT /account-keys/update/{ID}` — admin, client

**Request:**

```http
PUT /account-keys/update/01a0eea2-2296-71ae-bd0b-9bee0cd7106f
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "callback_url": "https://client.example.com/translator/callback"
}
```

| Field          | Tipe             | Wajib | Keterangan                                                            |
| -------------- | ---------------- | ----- | --------------------------------------------------------------------- |
| `secret_key`   | string(16-255)   | –     | nilai baru untuk rotasi secret                                        |
| `callback_url` | URL(500) \| null | –     | kirim `null` untuk menghapus callback URL (client beralih ke polling) |

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Account key updated successfully",
  "data": {
    "id": "01a0eea2-2296-71ae-bd0b-9bee0cd7106f",
    "account_id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
    "account": {
      "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
      "full_name": "Client",
      "email": "devs.trisnasejati@gmail.com"
    },
    "callback_url": "https://client.example.com/translator/callback",
    "secret_key_masked": "********",
    "has_secret_key": true,
    "created_at": "2026-09-29T19:26:38.000Z"
  }
}
```

**Error:** `400` body kosong (`Nothing to update`) / URL tidak valid, `403` key milik akun lain, `404` key tidak ditemukan.

### 4.14 Hapus API Key

**Deskripsi:** Menghapus API key. Client hanya boleh menghapus key miliknya.

**Endpoint:** `DELETE /account-keys/delete/{ID}` — admin, client

**Request:**

```http
DELETE /account-keys/delete/01a0eea2-2296-71ae-bd0b-9bee0cd7106f
Authorization: Bearer <access_token>
```

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Account key deleted successfully",
  "data": null
}
```

### 4.15 Daftar Riwayat Translasi

**Deskripsi:** Daftar job translasi beserta status dan status callback. Admin melihat semua (boleh filter `account_id`); client hanya riwayat miliknya.

**Endpoint:** `GET /histories` — admin, client

**Request:**

```http
GET /histories?page=1&limit=10&status=translated&translate_from=id&translate_to=en&date_from=2026-09-01T00:00:00.000Z
Authorization: Bearer <access_token>
```

| Query             | Default | Keterangan                                                    |
| ----------------- | ------- | ------------------------------------------------------------- |
| `search`          | –       | dicari pada `reference_id`, konten asli, dan hasil terjemahan |
| `status`          | –       | `requested` / `translated` / `failed`                         |
| `callback_status` | –       | `open` / `close`                                              |
| `driver_id`       | –       | filter driver                                                 |
| `translate_from`  | –       | kode bahasa sumber                                            |
| `translate_to`    | –       | kode bahasa tujuan                                            |
| `reference_id`    | –       | filter id milik client                                        |
| `date_from`       | –       | batas bawah `requested_at` (ISO 8601, inklusif)               |
| `date_to`         | –       | batas atas `requested_at` (ISO 8601, inklusif)                |
| `account_id`      | –       | **admin saja**, filter pemilik job                            |

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Histories retrieved successfully",
  "data": [
    {
      "id": "01a0eea4-dc8a-7a15-b4e1-7ad72ade53d4",
      "account_id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
      "account": {
        "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
        "full_name": "Client",
        "email": "devs.trisnasejati@gmail.com"
      },
      "driver_id": "gemini-3.8-flash",
      "driver": {
        "id": "gemini-3.8-flash",
        "name": "Gemini Flash",
        "type": "ai",
        "status": "active"
      },
      "translate_from": "id",
      "translate_to": "en",
      "reference_id": "INV-2026-0001",
      "reference_content": "Selamat pagi, apa kabar?",
      "translated_content": "Good morning, how are you?",
      "status": "translated",
      "requested_at": "2026-09-29T19:29:36.907Z",
      "translated_at": "2026-09-29T19:29:38.000Z",
      "callback_status": "close",
      "callback_retry": 1,
      "callback_at": "2026-09-29T19:29:38.518Z"
    }
  ],
  "meta": {
    "page": 1,
    "limit": 10,
    "total": 1,
    "totalPages": 1,
    "hasNext": false,
    "hasPrevious": false
  }
}
```

Status job: `requested` (menunggu/diproses), `translated` (sukses), `failed` (gagal). Status callback: `open` (masih akan dikirim), `close` (sudah terkirim / diabaikan).

### 4.16 Detail Riwayat Translasi

**Deskripsi:** Detail satu job translasi. Client hanya boleh mengakses miliknya (`403` bila bukan).

**Endpoint:** `GET /histories/detail/{ID}` — admin, client

**Request:**

```http
GET /histories/detail/01a0eea4-dc8a-7a15-b4e1-7ad72ade53d4
Authorization: Bearer <access_token>
```

**Response:** `200 OK` — struktur `data` sama dengan item pada `GET /histories`, dengan `message: "History retrieved successfully"`.

```json
{
  "success": true,
  "message": "History retrieved successfully",
  "data": {
    "id": "01a0eea4-dc8a-7a15-b4e1-7ad72ade53d4",
    "account_id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
    "account": {
      "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
      "full_name": "Client",
      "email": "devs.trisnasejati@gmail.com"
    },
    "driver_id": "gemini-3.8-flash",
    "driver": {
      "id": "gemini-3.8-flash",
      "name": "Gemini Flash",
      "type": "ai",
      "status": "active"
    },
    "translate_from": "id",
    "translate_to": "en",
    "reference_id": "INV-2026-0001",
    "reference_content": "Selamat pagi, apa kabar?",
    "translated_content": "Good morning, how are you?",
    "status": "translated",
    "requested_at": "2026-09-29T19:29:36.907Z",
    "translated_at": "2026-09-29T19:29:38.000Z",
    "callback_status": "close",
    "callback_retry": 1,
    "callback_at": "2026-09-29T19:29:38.518Z"
  }
}
```

### 4.17 Kirim Ulang Callback

**Deskripsi:** Membuka kembali callback (`callback_status = open`, `callback_retry = 0`) dan mengirim ulang perintah callback ke RabbitMQ. Berguna setelah callback gagal atau `callback_url` diperbaiki. Client hanya untuk job miliknya.

**Endpoint:** `POST /histories/resend-callback/{ID}` — admin, client

**Request:**

```http
POST /histories/resend-callback/01a0eea4-dc8a-7a15-b4e1-7ad72ade53d4
Authorization: Bearer <access_token>
```

**Response:** `201 Created` — detail history terbaru.

```json
{
  "success": true,
  "message": "Callback re-queued successfully",
  "data": {
    "id": "01a0eea4-dc8a-7a15-b4e1-7ad72ade53d4",
    "account_id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
    "account": {
      "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
      "full_name": "Client",
      "email": "devs.trisnasejati@gmail.com"
    },
    "driver_id": "gemini-3.8-flash",
    "driver": {
      "id": "gemini-3.8-flash",
      "name": "Gemini Flash",
      "type": "ai",
      "status": "active"
    },
    "translate_from": "id",
    "translate_to": "en",
    "reference_id": "INV-2026-0001",
    "reference_content": "Selamat pagi, apa kabar?",
    "translated_content": "Good morning, how are you?",
    "status": "translated",
    "requested_at": "2026-09-29T19:29:36.907Z",
    "translated_at": "2026-09-29T19:29:38.000Z",
    "callback_status": "open",
    "callback_retry": 0,
    "callback_at": null
  }
}
```

**Error:** `403` job milik akun lain, `404` history tidak ditemukan, `503` broker tidak tersedia.

---

## 5. Endpoint Khusus Admin

Seluruh endpoint pada bagian ini memerlukan `Authorization: Bearer <access_token>` milik akun ber-role `admin`. Response menggunakan envelope yang sama seperti [bagian 1.1](#11-format-response-sukses).

### 5.1 Daftar Akun

**Deskripsi:** Daftar semua akun (admin & client) dengan filter status/role.

**Endpoint:** `GET /accounts` — admin

**Request:**

```http
GET /accounts?page=1&limit=10&role=client&status=active
Authorization: Bearer <access_token>
```

| Query    | Default | Keterangan                        |
| -------- | ------- | --------------------------------- |
| `search` | –       | dicari pada `full_name` / `email` |
| `status` | –       | `active` / `inactive`             |
| `role`   | –       | `admin` / `client`                |

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Accounts retrieved successfully",
  "data": [
    {
      "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
      "full_name": "Client",
      "email": "devs.trisnasejati@gmail.com",
      "role": "client",
      "status": "active",
      "max_rpm": 0,
      "max_rpd": 0,
      "created_at": "2026-09-29T19:26:38.000Z",
      "updated_at": "2026-09-29T19:26:38.000Z"
    }
  ],
  "meta": {
    "page": 1,
    "limit": 10,
    "total": 1,
    "totalPages": 1,
    "hasNext": false,
    "hasPrevious": false
  }
}
```

### 5.2 Detail Akun

**Deskripsi:** Detail satu akun berdasarkan id.

**Endpoint:** `GET /accounts/detail/{ID}` — admin

**Request:**

```http
GET /accounts/detail/01a0eea2-221c-70cd-83d0-56e95a65c37b
Authorization: Bearer <access_token>
```

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Account retrieved successfully",
  "data": {
    "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
    "full_name": "Client",
    "email": "devs.trisnasejati@gmail.com",
    "role": "client",
    "status": "active",
    "max_rpm": 0,
    "max_rpd": 0,
    "created_at": "2026-09-29T19:26:38.000Z",
    "updated_at": "2026-09-29T19:26:38.000Z"
  }
}
```

### 5.3 Tambah Akun

**Deskripsi:** Membuat akun admin/client baru. Password disimpan sebagai hash bcrypt.

**Endpoint:** `POST /accounts/insert` — admin

**Request:**

```http
POST /accounts/insert
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "full_name": "PT Contoh Digital",
  "email": "integrasi@contoh.co.id",
  "password": "rahasia123",
  "role": "client",
  "status": "active",
  "max_rpm": 60,
  "max_rpd": 5000
}
```

| Field       | Tipe                   | Wajib | Keterangan                                      |
| ----------- | ---------------------- | ----- | ----------------------------------------------- |
| `full_name` | string(100)            | ✅    | nama lengkap                                    |
| `email`     | string(150)            | ✅    | unik, otomatis lowercase                        |
| `password`  | string(6-100)          | ✅    | disimpan sebagai hash bcrypt                    |
| `role`      | `admin` \| `client`    | –     | default `client`                                |
| `status`    | `active` \| `inactive` | –     | default `active`                                |
| `max_rpm`   | int ≥ 0                | –     | batas request API per menit (`0` = tanpa batas) |
| `max_rpd`   | int ≥ 0                | –     | batas request API per hari (`0` = tanpa batas)  |

**Response:** `201 Created`

```json
{
  "success": true,
  "message": "Account created successfully",
  "data": {
    "id": "01a0eea5-1b20-7f3a-9c44-1d5e7f9a0b12",
    "full_name": "PT Contoh Digital",
    "email": "integrasi@contoh.co.id",
    "role": "client",
    "status": "active",
    "max_rpm": 60,
    "max_rpd": 5000,
    "created_at": "2026-09-30T02:35:00.000Z",
    "updated_at": "2026-09-30T02:35:00.000Z"
  }
}
```

**Error:** `409` email sudah terpakai, `400` field tidak valid / field di luar kontrak.

### 5.4 Ubah Akun

**Deskripsi:** Mengubah data akun. Semua field opsional; kirim `password` baru untuk menggantinya. `email` harus tetap unik.

**Endpoint:** `PUT /accounts/update/{ID}` — admin

**Request:**

```http
PUT /accounts/update/01a0eea2-221c-70cd-83d0-56e95a65c37b
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "status": "inactive",
  "max_rpm": 30,
  "max_rpd": 1000
}
```

Body: `full_name`?, `email`?, `password`?, `role`?, `status`?, `max_rpm`?, `max_rpd`?.

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Account updated successfully",
  "data": {
    "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
    "full_name": "Client",
    "email": "devs.trisnasejati@gmail.com",
    "role": "client",
    "status": "inactive",
    "max_rpm": 30,
    "max_rpd": 1000,
    "created_at": "2026-09-29T19:26:38.000Z",
    "updated_at": "2026-09-30T02:36:00.000Z"
  }
}
```

**Error:** `400` tidak ada field yang dikirim (`Nothing to update`), `404` akun tidak ditemukan, `409` email duplikat.

### 5.5 Hapus Akun

**Deskripsi:** Menghapus akun beserta API key dan akses driver-nya (cascade). Admin tidak dapat menghapus akunnya sendiri.

**Endpoint:** `DELETE /accounts/delete/{ID}` — admin

**Request:**

```http
DELETE /accounts/delete/01a0eea5-1b20-7f3a-9c44-1d5e7f9a0b12
Authorization: Bearer <access_token>
```

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Account deleted successfully",
  "data": null
}
```

**Error:** `404` akun tidak ditemukan, `409` mencoba menghapus akun sendiri.

### 5.6 Tambah Bahasa

**Deskripsi:** Menambah kode bahasa baru yang bisa dipakai translasi.

**Endpoint:** `POST /languages/insert` — admin

**Request:**

```http
POST /languages/insert
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "id": "ja",
  "name": "Japanese",
  "status": "active"
}
```

| Field    | Tipe                   | Wajib | Keterangan                         |
| -------- | ---------------------- | ----- | ---------------------------------- |
| `id`     | string(2)              | ✅    | kode ISO 639-1, otomatis lowercase |
| `name`   | string(100)            | ✅    | nama bahasa                        |
| `status` | `active` \| `inactive` | –     | default `active`                   |

**Response:** `201 Created`

```json
{
  "success": true,
  "message": "Language created successfully",
  "data": { "id": "ja", "name": "Japanese", "status": "active" }
}
```

**Error:** `409` kode bahasa sudah ada, `400` format kode salah (harus 2 huruf).

### 5.7 Ubah Bahasa

**Deskripsi:** Mengubah nama dan/atau status bahasa. Kode `id` tidak dapat diubah.

**Endpoint:** `PUT /languages/update/{ID}` — admin

**Request:**

```http
PUT /languages/update/ja
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "name": "Japanese (日本語)",
  "status": "active"
}
```

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Language updated successfully",
  "data": { "id": "ja", "name": "Japanese (日本語)", "status": "active" }
}
```

### 5.8 Tambah Driver

**Deskripsi:** Mendaftarkan driver baru. Prefix `id` menentukan engine yang mengeksekusi: `gemini*`, `claude*`, `deepseek*`, `google-translate*`, `api-google-translate`. `secret_key` (kredensial provider) disimpan terenkripsi AES-256-GCM dan tidak pernah dikembalikan.

**Endpoint:** `POST /drivers/insert` — admin

**Request:**

```http
POST /drivers/insert
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "id": "gemini-3.8-flash",
  "type": "ai",
  "name": "Gemini Flash",
  "status": "active",
  "secret_key": "AIzaSyContohApiKeyProvider",
  "max_rpm": 10,
  "max_rpd": 250
}
```

| Field        | Tipe                   | Wajib | Keterangan                                |
| ------------ | ---------------------- | ----- | ----------------------------------------- |
| `id`         | string(3-20)           | ✅    | lowercase, harus diawali prefix engine    |
| `type`       | `ai` \| `api`          | ✅    | jenis driver                              |
| `name`       | string(100)            | ✅    | nama tampilan                             |
| `status`     | `active` \| `inactive` | –     | default `active`                          |
| `secret_key` | string                 | –     | kredensial provider, disimpan terenkripsi |
| `max_rpm`    | int ≥ 0                | –     | batas task per menit (`0` = tanpa batas)  |
| `max_rpd`    | int ≥ 0                | –     | batas task per hari (`0` = tanpa batas)   |

**Response:** `201 Created`

```json
{
  "success": true,
  "message": "Driver created successfully",
  "data": {
    "id": "gemini-3.8-flash",
    "type": "ai",
    "name": "Gemini Flash",
    "status": "active",
    "max_rpm": 10,
    "max_rpd": 250,
    "has_secret_key": true,
    "engine": "gemini"
  }
}
```

**Error:** `409` driver sudah ada, `400` prefix id tidak dikenal.

### 5.9 Ubah Driver

**Deskripsi:** Mengubah data driver. Kirim `secret_key` baru untuk menggantinya, atau string kosong untuk menghapusnya. `id` tidak dapat diubah.

**Endpoint:** `PUT /drivers/update/{ID}` — admin

**Request:**

```http
PUT /drivers/update/gemini-3.8-flash
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "status": "inactive",
  "max_rpd": 500
}
```

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Driver updated successfully",
  "data": {
    "id": "gemini-3.8-flash",
    "type": "ai",
    "name": "Gemini Flash",
    "status": "inactive",
    "max_rpm": 10,
    "max_rpd": 500,
    "has_secret_key": true,
    "engine": "gemini"
  }
}
```

### 5.10 Hapus Driver

**Deskripsi:** Menghapus driver. Ditolak bila driver sudah dipakai pada `histories` — nonaktifkan saja (`status: inactive`) agar riwayat tetap utuh.

**Endpoint:** `DELETE /drivers/delete/{ID}` — admin

**Request:**

```http
DELETE /drivers/delete/api-google-translate
Authorization: Bearer <access_token>
```

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Driver deleted successfully",
  "data": null
}
```

**Error:** `404` driver tidak ditemukan, `409` driver masih dipakai di `histories`.

### 5.11 Daftar Akses Driver per Akun

**Deskripsi:** Daftar relasi `account_drivers` — driver mana saja yang boleh dipakai oleh sebuah akun.

**Endpoint:** `GET /account-drivers` — admin

**Request:**

```http
GET /account-drivers?page=1&limit=10&account_id=01a0eea2-221c-70cd-83d0-56e95a65c37b
Authorization: Bearer <access_token>
```

| Query        | Default | Keterangan                                     |
| ------------ | ------- | ---------------------------------------------- |
| `search`     | –       | dicari pada nama/email akun dan nama/id driver |
| `account_id` | –       | filter akun (UUID)                             |
| `driver_id`  | –       | filter driver                                  |

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Account drivers retrieved successfully",
  "data": [
    {
      "id": "01a0eea2-2301-7a4b-9a11-9f2f1a4b8c33",
      "account_id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
      "account": {
        "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
        "full_name": "Client",
        "email": "devs.trisnasejati@gmail.com"
      },
      "driver_id": "gemini-3.8-flash",
      "driver": {
        "id": "gemini-3.8-flash",
        "name": "Gemini Flash",
        "type": "ai",
        "status": "active"
      },
      "created_at": "2026-09-29T19:26:38.000Z"
    }
  ],
  "meta": {
    "page": 1,
    "limit": 10,
    "total": 1,
    "totalPages": 1,
    "hasNext": false,
    "hasPrevious": false
  }
}
```

### 5.12 Beri Akses Driver

**Deskripsi:** Memberikan akses satu driver ke satu akun. Pasangan akun+driver harus unik. Tanpa akses ini `POST /translate` membalas `403`.

**Endpoint:** `POST /account-drivers/insert` — admin

**Request:**

```http
POST /account-drivers/insert
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "account_id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
  "driver_id": "gemini-3.8-flash"
}
```

**Response:** `201 Created`

```json
{
  "success": true,
  "message": "Driver access granted successfully",
  "data": {
    "id": "01a0eea2-2301-7a4b-9a11-9f2f1a4b8c33",
    "account_id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
    "account": {
      "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
      "full_name": "Client",
      "email": "devs.trisnasejati@gmail.com"
    },
    "driver_id": "gemini-3.8-flash",
    "driver": {
      "id": "gemini-3.8-flash",
      "name": "Gemini Flash",
      "type": "ai",
      "status": "active"
    },
    "created_at": "2026-09-30T02:40:00.000Z"
  }
}
```

**Error:** `400` akun/driver tidak ditemukan, `409` pasangan sudah ada.

### 5.13 Ubah Akses Driver

**Deskripsi:** Mengubah akun dan/atau driver dari satu baris akses. Minimal salah satu berubah, jika tidak akan dibalas `400 Nothing to update`.

**Endpoint:** `PUT /account-drivers/update/{ID}` — admin

**Request:**

```http
PUT /account-drivers/update/01a0eea2-2301-7a4b-9a11-9f2f1a4b8c33
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "driver_id": "claude-sonnet-4-5"
}
```

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Driver access updated successfully",
  "data": {
    "id": "01a0eea2-2301-7a4b-9a11-9f2f1a4b8c33",
    "account_id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
    "account": {
      "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
      "full_name": "Client",
      "email": "devs.trisnasejati@gmail.com"
    },
    "driver_id": "claude-sonnet-4-5",
    "driver": {
      "id": "claude-sonnet-4-5",
      "name": "Claude Sonnet",
      "type": "ai",
      "status": "active"
    },
    "created_at": "2026-09-29T19:26:38.000Z"
  }
}
```

### 5.14 Hapus Akses Driver

**Deskripsi:** Mencabut akses driver dari sebuah akun.

**Endpoint:** `DELETE /account-drivers/delete/{ID}` — admin

**Request:**

```http
DELETE /account-drivers/delete/01a0eea2-2301-7a4b-9a11-9f2f1a4b8c33
Authorization: Bearer <access_token>
```

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "Driver access deleted successfully",
  "data": null
}
```

### 5.15 Ulangi Terjemahan

**Deskripsi:** Mengulang proses terjemahan sebuah job: `status = requested`, `translated_at = null`, callback dibuka kembali, dan perintah `translate` dikirim ulang. Hasil lama tetap tersimpan sampai hasil baru tersedia.

**Endpoint:** `POST /histories/retranslate/{ID}` — admin

**Request:**

```http
POST /histories/retranslate/01a0eea4-dc8a-7a15-b4e1-7ad72ade53d4
Authorization: Bearer <access_token>
```

**Response:** `201 Created`

```json
{
  "success": true,
  "message": "Translation re-queued successfully",
  "data": {
    "id": "01a0eea4-dc8a-7a15-b4e1-7ad72ade53d4",
    "account_id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
    "account": {
      "id": "01a0eea2-221c-70cd-83d0-56e95a65c37b",
      "full_name": "Client",
      "email": "devs.trisnasejati@gmail.com"
    },
    "driver_id": "gemini-3.8-flash",
    "driver": {
      "id": "gemini-3.8-flash",
      "name": "Gemini Flash",
      "type": "ai",
      "status": "active"
    },
    "translate_from": "id",
    "translate_to": "en",
    "reference_id": "INV-2026-0001",
    "reference_content": "Selamat pagi, apa kabar?",
    "translated_content": "Good morning, how are you?",
    "status": "requested",
    "requested_at": "2026-09-29T19:29:36.907Z",
    "translated_at": null,
    "callback_status": "open",
    "callback_retry": 0,
    "callback_at": null
  }
}
```

**Error:** `404` history tidak ditemukan, `503` broker tidak tersedia.

### 5.16 Hapus Riwayat Translasi

**Deskripsi:** Menghapus permanen satu job translasi beserta datanya.

**Endpoint:** `DELETE /histories/delete/{ID}` — admin

**Request:**

```http
DELETE /histories/delete/01a0eea4-dc8a-7a15-b4e1-7ad72ade53d4
Authorization: Bearer <access_token>
```

**Response:** `200 OK`

```json
{
  "success": true,
  "message": "History deleted successfully",
  "data": null
}
```

---

## 6. Callback ke Client

Setelah job selesai (sukses maupun gagal), server mengirim `POST` ke `callback_url` milik key yang dipakai.

**Headers:**

| Header          | Isi                                                        |
| --------------- | ---------------------------------------------------------- |
| `Content-Type`  | `application/json`                                         |
| `key_id`        | id `account_keys` yang dipakai saat request                |
| `Authorization` | `Bearer <JWT HS256, payload { account_id, reference_id }>` |

**Body:**

```json
{
  "status": "translated",
  "translate_from": "id",
  "translate_to": "en",
  "reference_id": "INV-2026-0001",
  "translated_content": "Good morning, how are you?",
  "translated_at": "2026-09-29T19:29:38.518Z"
}
```

| Field                | Tipe             | Keterangan                              |
| -------------------- | ---------------- | --------------------------------------- |
| `status`             | string           | `translated` atau `failed`              |
| `translate_from`     | string(2)        | kode bahasa sumber                      |
| `translate_to`       | string(2)        | kode bahasa tujuan                      |
| `reference_id`       | string           | sama dengan `reference_id` saat request |
| `translated_content` | string \| null   | hasil terjemahan; `null` bila `failed`  |
| `translated_at`      | ISO 8601 \| null | waktu selesai terjemahan                |

**Yang harus dilakukan client:**

1. Ambil `key_id` dari header, cari `secret_key` yang bersesuaian di sisi client.
2. Verifikasi `Authorization` Bearer dengan `secret_key` tersebut (HS256).
3. Bandingkan `reference_id` di payload token dengan `reference_id` di body.
4. Balas HTTP `2xx` bila sudah diterima. Selain `2xx` (atau timeout `CALLBACK_TIMEOUT_MS`, default 15 detik) dianggap gagal → dijadwalkan ulang (default 5 menit), maksimal `CALLBACK_MAX_ATTEMPTS` (2) percobaan.

Contoh verifikasi callback (Node/TypeScript, gaya `scripts/callback-receiver.ts`):

```ts
import { createHmac, timingSafeEqual } from 'node:crypto';

function verifyCallback(secretKey: string, token: string) {
  const [header, payload, signature] = token.split('.');
  const expected = createHmac('sha256', secretKey)
    .update(`${header}.${payload}`)
    .digest('base64url');
  const received = Buffer.from(signature);
  const expectedBuffer = Buffer.from(expected);

  if (
    received.length !== expectedBuffer.length ||
    !timingSafeEqual(received, expectedBuffer)
  ) {
    throw new Error('Signature callback tidak valid');
  }

  const claims = JSON.parse(
    Buffer.from(payload, 'base64url').toString('utf8'),
  ) as { account_id: string; reference_id: string; exp: number };

  if (claims.exp < Math.floor(Date.now() / 1000)) {
    throw new Error('Token callback kedaluwarsa');
  }

  return claims;
}
```

> Tidak ada `callback_url`? `callback_enabled` pada response `/translate` bernilai `false`. Ambil hasilnya dengan polling `GET /histories/detail/{ID}` memakai access token (panel) — untuk integrasi mesin, set `callback_url` pada `POST /account-keys/insert` atau `PUT /account-keys/update/{ID}`.

---

## 7. Error & Status Code

Contoh response error yang sering muncul:

**400 — validasi body (`Validation failed`)**

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": [
    { "message": "driver_id is required" },
    { "message": "translate_from must be a language code" }
  ],
  "meta": {
    "path": "/translate",
    "method": "POST",
    "statusCode": 400,
    "timestamp": "2026-09-30T02:30:00.000Z"
  }
}
```

**401 — token tidak valid**

```json
{
  "success": false,
  "message": "Signature token is invalid or expired",
  "errors": [],
  "meta": {
    "path": "/translate",
    "method": "POST",
    "statusCode": 401,
    "timestamp": "2026-09-30T02:30:00.000Z"
  }
}
```

**403 — akses ditolak**

```json
{
  "success": false,
  "message": "Your account does not have access to driver \"claude-sonnet-4-5\"",
  "errors": [],
  "meta": {
    "path": "/translate",
    "method": "POST",
    "statusCode": 403,
    "timestamp": "2026-09-30T02:30:00.000Z"
  }
}
```

**409 — konflik data**

```json
{
  "success": false,
  "message": "The data already exists (duplicate entry)",
  "errors": [],
  "meta": {
    "path": "/account-drivers/insert",
    "method": "POST",
    "statusCode": 409,
    "timestamp": "2026-09-30T02:30:00.000Z"
  }
}
```

**429 — quota akun terlampaui**

```json
{
  "success": false,
  "message": "Request quota exceeded: 5/5 per minute and 12/250 per day",
  "errors": [
    {
      "field": "max_rpm",
      "message": "Requests per minute limit reached (5), retry after 2026-09-30T02:31:00.000Z"
    },
    {
      "field": "max_rpd",
      "message": "Requests per day limit reached (250), reset at 2026-10-01T00:00:00.000Z"
    }
  ],
  "meta": {
    "path": "/translate",
    "method": "POST",
    "statusCode": 429,
    "timestamp": "2026-09-30T02:30:00.000Z"
  }
}
```

Catatan rate limit:

- `max_rpm` dihitung dari jumlah job akun dalam **60 detik terakhir**, `max_rpd` sejak **00:00 UTC**.
- Nilai `0` berarti **tanpa batas**.
- Limit driver dicek worker sebelum memanggil provider; bila terlampaui job dijadwalkan ulang (bukan gagal) sampai `TRANSLATE_MAX_RETRY` (default 5).

---

## Lampiran: Data Seeder untuk Uji Coba

| Role   | Email                         | Password    |
| ------ | ----------------------------- | ----------- |
| admin  | `halo.trisnasejati@gmail.com` | `admin123`  |
| client | `devs.trisnasejati@gmail.com` | `client123` |

Seeder juga membuat 1 API key per akun (menampilkan `key_id` + `secret_key` di log — simpan, karena hanya tampil sekali), bahasa `id`/`en`, driver `gemini-3.8-flash` dan `api-google-translate`, serta memberikan kedua driver tersebut ke kedua akun.

Utilitas bantu di repo:

| Perintah                                                              | Fungsi                                              |
| --------------------------------------------------------------------- | --------------------------------------------------- |
| `npm run token:sign -- <key_id> <account_id> <reference_id> <secret>` | membuat signature token untuk uji `POST /translate` |
| `npm run callback:listen`                                             | server contoh penerima callback (port 4000)         |
