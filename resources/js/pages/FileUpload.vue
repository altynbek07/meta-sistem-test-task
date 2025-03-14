<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { toast } from '@/components/ui/toast';
import { AlertCircle, CheckCircle2, Upload, RefreshCw, ExternalLink } from 'lucide-vue-next';
import AuthLayout from '@/layouts/AuthLayout.vue';

const file = ref<File | null>(null);
const uploadProgress = ref(0);
const uploadStatus = ref('idle'); // idle, preparing, uploading, paused, completed, error
const uploadId = ref<string | null>(null);
const message = ref('');
const chunkSize = 1024 * 512; // 512KB chunks
const totalChunks = ref(0);
const uploadedChunks = reactive(new Set<number>());
const fileUrl = ref<string | null>(null);

// Вычисляемое свойство для отображения процента загрузки
const progressPercentage = computed(() => {
    if (!totalChunks.value) return 0;
    return Math.round((uploadedChunks.size / totalChunks.value) * 100);
});

// Функция для выбора файла
const handleFileSelect = (event: Event) => {
    const input = event.target as HTMLInputElement;
    if (input.files && input.files.length > 0) {
        file.value = input.files[0];
        uploadStatus.value = 'idle';
        uploadProgress.value = 0;
        uploadedChunks.clear();
        message.value = '';
    }
};

// Инициализация загрузки
const initializeUpload = async () => {
    if (!file.value) return;

    uploadStatus.value = 'preparing';
    message.value = 'Подготовка к загрузке...';

    try {
        const formData = new FormData();
        formData.append('filename', file.value.name);
        formData.append('filesize', file.value.size.toString());
        formData.append('filetype', file.value.type);

        const response = await fetch('/api/upload/init', {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            }
        });

        if (!response.ok) {
            throw new Error('Не удалось инициализировать загрузку');
        }

        const data = await response.json();
        uploadId.value = data.upload_id;

        // Сохраняем ID загрузки в localStorage для возобновления
        localStorage.setItem('current_upload_id', data.upload_id);
        localStorage.setItem('current_upload_filename', file.value.name);
        localStorage.setItem('current_upload_size', file.value.size.toString());

        // Рассчитываем количество чанков
        totalChunks.value = Math.ceil(file.value.size / chunkSize);
        localStorage.setItem('current_upload_chunks', totalChunks.value.toString());

        // Начинаем загрузку
        startUpload();
    } catch (error) {
        console.error('Ошибка инициализации загрузки:', error);
        uploadStatus.value = 'error';
        message.value = 'Не удалось инициализировать загрузку';
    }
};

// Загрузка чанка
const uploadChunk = async (chunkIndex: number) => {
    if (!file.value || !uploadId.value) return;

    // Проверяем, был ли уже загружен этот чанк
    if (uploadedChunks.has(chunkIndex)) {
        return true;
    }

    try {
        const start = chunkIndex * chunkSize;
        const end = Math.min(start + chunkSize, file.value.size);
        const chunk = file.value.slice(start, end);

        const formData = new FormData();
        formData.append('chunk', new File([chunk], 'chunk'));
        formData.append('index', chunkIndex.toString());
        formData.append('total_chunks', totalChunks.value.toString());
        formData.append('filename', file.value.name);

        const response = await fetch(`/api/upload/chunk/${uploadId.value}`, {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            }
        });

        if (!response.ok) {
            throw new Error(`Не удалось загрузить чанк ${chunkIndex}`);
        }

        // Помечаем чанк как загруженный
        uploadedChunks.add(chunkIndex);

        // Сохраняем прогресс в localStorage
        localStorage.setItem('uploaded_chunks', JSON.stringify(Array.from(uploadedChunks)));

        // Обновляем прогресс
        uploadProgress.value = progressPercentage.value;

        return true;
    } catch (error) {
        console.error(`Ошибка загрузки чанка ${chunkIndex}:`, error);
        return false;
    }
};

// Завершение загрузки
const finalizeUpload = async () => {
    if (!file.value || !uploadId.value) return;

    try {
        const formData = new FormData();
        formData.append('filename', file.value.name);
        formData.append('total_chunks', totalChunks.value.toString());

        const response = await fetch(`/api/upload/finalize/${uploadId.value}`, {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            }
        });

        if (!response.ok) {
            throw new Error('Не удалось завершить загрузку');
        }

        const data = await response.json();
        uploadStatus.value = 'completed';
        message.value = `Файл успешно загружен: ${data.filename}`;
        fileUrl.value = data.url; // Сохраняем URL загруженного файла

        // Очищаем данные о загрузке из localStorage
        clearUploadData();

        toast({
            title: 'Загрузка завершена',
            description: `Файл ${data.filename} успешно загружен`,
            variant: 'success',
        });
    } catch (error) {
        console.error('Ошибка завершения загрузки:', error);
        uploadStatus.value = 'error';
        message.value = 'Не удалось завершить загрузку';
    }
};

// Основная функция загрузки файла
const startUpload = async () => {
    if (!file.value || !uploadId.value) return;

    uploadStatus.value = 'uploading';
    message.value = 'Загрузка файла...';

    // Загружаем чанки последовательно
    for (let i = 0; i < totalChunks.value; i++) {
        if (uploadStatus.value === 'paused') {
            message.value = 'Загрузка приостановлена';
            return;
        }

        const success = await uploadChunk(i);

        // Если чанк не удалось загрузить, пауза в загрузке
        if (!success) {
            uploadStatus.value = 'paused';
            message.value = 'Загрузка приостановлена из-за ошибки соединения';
            return;
        }
    }

    // Если все чанки загружены, завершаем загрузку
    if (uploadedChunks.size === totalChunks.value) {
        await finalizeUpload();
    }
};

// Возобновление загрузки
const resumeUpload = async () => {
    if (uploadStatus.value === 'uploading') return;

    if (!uploadId.value && !localStorage.getItem('current_upload_id')) {
        // Нет данных для возобновления
        return;
    }

    if (!uploadId.value) {
        // Восстанавливаем данные из localStorage
        uploadId.value = localStorage.getItem('current_upload_id');

        if (!file.value) {
            message.value = 'Выберите тот же файл для возобновления загрузки';
            return;
        }

        const storedFilename = localStorage.getItem('current_upload_filename');
        const storedSize = localStorage.getItem('current_upload_size');

        if (storedFilename !== file.value.name || storedSize !== file.value.size.toString()) {
            message.value = 'Выбранный файл не соответствует предыдущей загрузке';
            return;
        }

        totalChunks.value = parseInt(localStorage.getItem('current_upload_chunks') || '0', 10);

        // Восстанавливаем список загруженных чанков
        const storedChunks = localStorage.getItem('uploaded_chunks');
        if (storedChunks) {
            const chunks = JSON.parse(storedChunks) as number[];
            chunks.forEach(chunk => uploadedChunks.add(chunk));
        }
    }

    uploadStatus.value = 'uploading';
    message.value = 'Возобновление загрузки...';

    // Проверяем статус на сервере
    try {
        const response = await fetch(`/api/upload/status/${uploadId.value}`);
        if (!response.ok) {
            throw new Error('Не удалось получить статус загрузки');
        }

        // Продолжаем загрузку с оставшихся чанков
        for (let i = 0; i < totalChunks.value; i++) {
            if (!uploadedChunks.has(i)) {
                const success = await uploadChunk(i);

                // Если чанк не удалось загрузить, пауза в загрузке
                if (!success) {
                    uploadStatus.value = 'paused';
                    message.value = 'Загрузка приостановлена из-за ошибки соединения';
                    return;
                }
            }
        }

        // Если все чанки загружены, завершаем загрузку
        if (uploadedChunks.size === totalChunks.value) {
            await finalizeUpload();
        }
    } catch (error) {
        console.error('Ошибка возобновления загрузки:', error);
        uploadStatus.value = 'error';
        message.value = 'Не удалось возобновить загрузку';
    }
};

// Пауза загрузки
const pauseUpload = () => {
    if (uploadStatus.value === 'uploading') {
        uploadStatus.value = 'paused';
        message.value = 'Загрузка приостановлена';
    }
};

// Очистка данных о загрузке
const clearUploadData = () => {
    localStorage.removeItem('current_upload_id');
    localStorage.removeItem('current_upload_filename');
    localStorage.removeItem('current_upload_size');
    localStorage.removeItem('current_upload_chunks');
    localStorage.removeItem('uploaded_chunks');
};

// Сброс загрузки
const resetUpload = () => {
    file.value = null;
    uploadProgress.value = 0;
    uploadStatus.value = 'idle';
    uploadId.value = null;
    message.value = '';
    uploadedChunks.clear();
    totalChunks.value = 0;
    fileUrl.value = null; // Очищаем URL файла
    clearUploadData();
};

// Проверка наличия незавершенной загрузки при загрузке страницы
onMounted(() => {
    const storedUploadId = localStorage.getItem('current_upload_id');
    if (storedUploadId) {
        uploadId.value = storedUploadId;
        uploadStatus.value = 'paused';
        message.value = 'Обнаружена незавершенная загрузка. Выберите тот же файл для возобновления.';
    }
});
</script>

<template>
    <AuthLayout>

        <Head title="Загрузка файлов" />

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <h2 class="text-lg font-medium mb-6">Загрузка файлов с возобновлением</h2>

                        <div class="mb-6">
                            <div class="space-y-2">
                                <label for="file-upload" class="block text-sm font-medium">
                                    Выберите файл для загрузки
                                </label>
                                <div class="flex items-center space-x-2">
                                    <input type="file" id="file-upload"
                                        class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 dark:file:bg-gray-700 dark:file:text-gray-200"
                                        @change="handleFileSelect" />
                                </div>
                            </div>

                            <div v-if="file" class="mt-4 space-y-4">
                                <div class="text-sm">
                                    <p><strong>Имя файла:</strong> {{ file.name }}</p>
                                    <p><strong>Размер:</strong> {{ (file.size / (1024 * 1024)).toFixed(2) }} МБ</p>
                                </div>

                                <div v-if="uploadStatus !== 'idle' && uploadStatus !== 'completed'" class="space-y-2">
                                    <div class="flex justify-between text-sm">
                                        <span>Прогресс загрузки</span>
                                        <span>{{ progressPercentage }}%</span>
                                    </div>
                                    <Progress :model-value="progressPercentage" class="w-full" />
                                </div>

                                <div v-if="message" class="text-sm p-3 rounded-md" :class="{
                                    'bg-yellow-50 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400': uploadStatus === 'paused',
                                    'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400': uploadStatus === 'error',
                                    'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400': uploadStatus === 'completed',
                                    'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400': ['preparing', 'uploading'].includes(uploadStatus)
                                }">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <AlertCircle v-if="uploadStatus === 'error'" class="h-4 w-4" />
                                            <CheckCircle2 v-if="uploadStatus === 'completed'" class="h-4 w-4" />
                                        </div>
                                        <div class="ml-2">{{ message }}</div>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-3">
                                    <Button v-if="uploadStatus === 'idle'" @click="initializeUpload" variant="default">
                                        <Upload class="h-4 w-4 mr-2" />
                                        Начать загрузку
                                    </Button>

                                    <Button v-if="uploadStatus === 'uploading'" @click="pauseUpload" variant="outline">
                                        Приостановить
                                    </Button>

                                    <Button v-if="uploadStatus === 'paused' || uploadStatus === 'error'"
                                        @click="resumeUpload" variant="default">
                                        <RefreshCw class="h-4 w-4 mr-2" />
                                        Возобновить загрузку
                                    </Button>

                                    <Button v-if="uploadStatus !== 'idle' && uploadStatus !== 'completed'"
                                        @click="resetUpload" variant="destructive">
                                        Отменить загрузку
                                    </Button>

                                    <div v-if="uploadStatus === 'completed' && fileUrl" class="w-full mt-2">
                                        <p class="text-sm mb-2">Ваш файл доступен по ссылке:</p>
                                        <a :href="fileUrl" target="_blank"
                                            class="inline-flex items-center text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                            Открыть загруженный файл
                                            <ExternalLink class="h-4 w-4 ml-1" />
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthLayout>
</template>