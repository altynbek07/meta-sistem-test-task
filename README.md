# File Upload System

A Laravel 12 and Vue 3 application that allows uploading large files with resumable capability, especially designed for unstable internet connections.

## Requirements

- Docker
- Docker Compose

## Installation

1. **Clone the repository:**
   ```bash
   git clone https://github.com/altynbek07/meta-sistem-test-task
   ```
2. **Copy environment file:**
   ```bash
   cp .env.example .env
   ```
3. **Start the project using Laravel Sail:**
   ```bash
   ./vendor/bin/sail up -d
   ```
4. **Install dependencies:**
   ```bash
   ./vendor/bin/sail composer install
   ./vendor/bin/sail npm install
   ```
5. **Generate application key:**
   ```bash
   ./vendor/bin/sail artisan key:generate
   ```
6. **Create storage link:**
   ```bash
   ./vendor/bin/sail artisan storage:link
   ```
7. **Build frontend assets:**
   ```bash
   ./vendor/bin/sail npm run build
   ```

## Features

### Large File Upload with Resume Capability

- Chunk-based file upload (512KB chunks)
- Auto-resume after connection interruptions
- Progress tracking and status updates
- File storage with public access URLs

### API Routes

- **POST** `/api/upload/init` - Initialize a new upload session
- **POST** `/api/upload/chunk/{uploadId}` - Upload a file chunk
- **POST** `/api/upload/finalize/{uploadId}` - Finalize the upload and combine chunks
- **GET** `/api/upload/status/{uploadId}` - Get the status of an upload

## Technical Implementation

### Frontend

- Vue 3 with Composition API
- TypeScript for better type safety
- Inertia.js for server-side rendering
- localStorage to track upload progress for resumption
- File chunking for reliable uploads

### Backend

- Laravel 12 File Storage API
- Chunk-based file handling
- UUID-based upload session tracking
- No database required for tracking uploads

## How It Works

1. A file is selected for upload and divided into chunks (512KB each)
2. Each chunk is uploaded sequentially
3. Upload progress is saved in browser's localStorage
4. If connection is interrupted, upload can be resumed from the last successful chunk
5. After all chunks are uploaded, they are combined on the server into the original file

## License

This project is licensed under the MIT License.

