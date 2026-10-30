# SmartNotes AI - PHP Backend & Database Integration Guide

Welcome to the backend service for **SmartNotes AI**, an AI-powered lecture and class notes Android application. This backend is built using PHP 8.x, MySQL, and PDO, hosted locally via XAMPP.

---

## 1. Directory Structure

```
ai_class_be/
├── auth/
│   ├── signup.php             # User registration (hashes password, generates auth token)
│   ├── login.php              # User login (verifies password, returns user & profile status)
│   ├── logout.php             # Invalidation of active session token
│   ├── forgot_password.php    # Generates 6-digit password reset code
│   └── reset_password.php     # Validates reset token and sets new password
├── common/
│   └── auth_middleware.php    # Token verification, Bearer token parser, ownership check
├── config/
│   └── config.php             # Database PDO connection, CORS headers, JSON helpers
├── dashboard/
│   └── dashboard_stats.php    # Dynamic dashboard counts (notes, subjects, quizzes) & recent notes
├── database/
│   └── ai_class.sql           # Complete MySQL database schema & seed data
├── notes/
│   ├── list_notes.php         # Notes listing with search and category filtering
│   ├── get_note.php           # Detailed note view with sections, takeaways, and bullets
│   ├── create_note.php        # Inserts a newly generated or handwritten note
│   ├── update_note.php        # Updates note content or toggles bookmark
│   └── delete_note.php        # Deletes a note (enforcing user ownership)
├── profile/
│   ├── profile_fetch.php      # Fetches student profile data
│   ├── profile_edit.php       # In-place profile updates (name, course, dept, semester)
│   └── profile_add.php        # Onboarding profile registration
├── smartnotes_ai_postman_collection.json # Complete Postman API testing collection (v2.1)
└── README.md
```

---

## 2. Database Setup (XAMPP & MySQL)

1. **Start Services:**
   - Open **XAMPP Control Panel**.
   - Start **Apache** and **MySQL**.
2. **Access phpMyAdmin:**
   - Open your browser and navigate to: `http://localhost/phpmyadmin/`
3. **Database Verification:**
   - Database name: `ai_class`
   - Default credentials: User: `root`, Password: `` (empty).
4. **Import Schema (if not already imported):**
   - Click on `ai_class` (or create database `ai_class` if absent).
   - Go to the **Import** tab.
   - Choose file: `C:\xampp\htdocs\ai_class_be\database\ai_class.sql`.
   - Click **Go**.

### Tables Created:
- `users`: User accounts with `password_hash` (`email`, `username`, `full_name`, `created_at`).
- `user_tokens`: Secure active session tokens (`token`, `user_id`, `expires_at`).
- `student_profiles`: Student details (`course`, `department`, `semester`, `roll_no`, `college_name`).
- `subjects`: Academic subjects and color codes (`Computer Networks`, `Operating Systems`, `DBMS`, `Algorithms`).
- `user_subjects`: Mapping of students to enrolled subjects.
- `notes`: Full class notes (`title`, `subject`, `summary`, `sections`, `takeaways`, `is_bookmarked`).
- `quizzes`: Practice quizzes linked to notes and subjects.

---

## 3. API Endpoints Reference

All requests accept `Content-Type: application/json` or URL-encoded form data.
Authenticated endpoints require header: `Authorization: Bearer <token>`.

### Authentication:
| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/auth/signup.php` | Register new user. Fields: `email`, `password`, `full_name` |
| `POST` | `/auth/login.php` | Login user. Fields: `email`, `password` |
| `POST` | `/auth/logout.php` | Invalidate current user session token |
| `POST` | `/auth/forgot_password.php` | Request password reset code. Fields: `email` |
| `POST` | `/auth/reset_password.php` | Reset password. Fields: `email`, `token`, `new_password` |

### Profile:
| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/profile/profile_fetch.php` | Fetch profile of authenticated user |
| `POST` | `/profile/profile_edit.php` | In-place edit of profile (name, course, department, semester) |
| `POST` | `/profile/profile_add.php` | Onboarding profile completion |

### Notes:
| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/notes/list_notes.php` | List user's notes. Query params: `search`, `category` |
| `GET` | `/notes/get_note.php?id=<id>` | Fetch detailed note by ID |
| `POST` | `/notes/create_note.php` | Create/save note. Fields: `title`, `subject`, `summary`, `sections`, `takeaways` |
| `POST` | `/notes/update_note.php` | Update note or toggle bookmark. Fields: `id`, `is_bookmarked` |
| `POST` | `/notes/delete_note.php` | Delete note. Fields: `id` |

### Dashboard:
| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/dashboard/dashboard_stats.php` | Get dynamic counts (`notes_count`, `subjects_count`, `quizzes_count`) and recent 2 notes |

---

## 4. Android App Configuration

### Physical Device (OnePlus Phone over Wi-Fi):
1. Connect your computer and your OnePlus phone to the **same Wi-Fi network**.
2. Find your PC's local IP address (e.g., `ipconfig` -> `172.23.176.83`).
3. Check `app/src/main/java/com/example/Simtas/data/remote/ApiConfig.kt`:
   ```kotlin
   const val BASE_URL = "http://172.23.176.83/ai_class_be"
   ```
4. If your PC IP changes, update `BASE_URL` in `ApiConfig.kt`.

### Android Emulator:
If testing inside an Android Studio emulator:
```kotlin
const val BASE_URL = "http://10.0.2.2/ai_class_be"
```

---

## 5. Postman Collection Testing

1. Open Postman.
2. Click **Import** -> Select `smartnotes_ai_postman_collection.json`.
3. The collection contains predefined tests that automatically store the returned `auth_token` and `test_note_id` into collection variables upon calling **Login** or **Create Note**.
4. Run requests in folder order:
   - `Auth/1. Login`
   - `Profile/1. Fetch Profile`
   - `Dashboard/1. Get Dashboard Stats`
   - `Notes/1. List Notes`
   - `Notes/3. Create Note`

---

## 6. AI Note Generation & Audio Transcription Note

- **Current State:** Note generation and audio transcription use realistic client-side structured templates with dynamic subject and topic injection, which are now **persistently saved to MySQL** via `/notes/create_note.php`.
- **Future Integration:** For production AI speech-to-text (e.g. OpenAI Whisper or Google Cloud Speech-to-Text) and note summarizing (Gemini Flash API), integrate an external API key in a dedicated backend proxy endpoint (e.g. `/ai/transcribe.php` and `/ai/generate.php`) to keep API keys secure on the server side.

