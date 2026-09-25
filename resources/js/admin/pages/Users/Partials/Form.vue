<script setup lang="ts">
import { computed, ref, watch } from "vue";
import type { PropType } from "vue";
import type { AdminRole } from "@/admin/types";
import {
    NInput,
    NAvatar,
    NIcon,
    NFormField,
    NButton,
    NSwitch,
    NCheckbox,
} from "nergous-ui-vue";

const props = defineProps({
    form: { type: Object, required: true },
    allRoles: { type: Array as PropType<AdminRole[]>, required: true },
    isEdit: { type: Boolean, default: false },
    user: { type: Object, default: null },
    // The own account cannot be blocked (the server rejects it too).
    isSelf: { type: Boolean, default: false },
});
const emit = defineEmits(["submit"]);

function toggleRole(name: string) {
    const set = new Set(props.form.roles);
    set.has(name) ? set.delete(name) : set.add(name);
    props.form.roles = Array.from(set);
}
function isSelected(name: string) {
    return props.form.roles.includes(name);
}

// Match the server password policy and exclude characters that are easy to confuse.
const PASSWORD_LENGTH = 20;
const UPPER = "ABCDEFGHJKMNPQRSTUVWXYZ";
const LOWER = "abcdefghijkmnpqrstuvwxyz";
const DIGITS = "23456789";
const SYMBOLS = "!@#$%^&*-_=+?";

// Keep generated passwords visible until the field is cleared.
const generated = ref(false);
// The value last written to the clipboard; editing the field invalidates it.
const copiedValue = ref("");
const copied = computed(
    () => copiedValue.value !== "" && copiedValue.value === props.form.password,
);

// Uniform random integer in [0, max): rejection sampling avoids the modulo bias.
function randomBelow(max: number): number {
    const limit = Math.floor(0x100000000 / max) * max;
    const buf = new Uint32Array(1);
    do crypto.getRandomValues(buf);
    while (buf[0] >= limit);
    return buf[0] % max;
}

function pickRandom(set: string, count: number): string[] {
    return Array.from({ length: count }, () => set[randomBelow(set.length)]);
}

function generatePassword() {
    const all = UPPER + LOWER + DIGITS + SYMBOLS;
    // Guarantee every required class, fill the rest from the full alphabet…
    const chars = [
        ...pickRandom(UPPER, 2),
        ...pickRandom(LOWER, 2),
        ...pickRandom(DIGITS, 2),
        ...pickRandom(SYMBOLS, 2),
        ...pickRandom(all, PASSWORD_LENGTH - 8),
    ];
    // …then shuffle so the class blocks don't sit at the start (Fisher–Yates).
    for (let i = chars.length - 1; i > 0; i--) {
        const j = randomBelow(i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }
    return chars.join("");
}

async function onGenerate() {
    const password = generatePassword();
    props.form.password = password;
    generated.value = true;
    await copyPassword();
}

async function copyPassword() {
    const password = props.form.password;
    if (!password) return;
    try {
        await navigator.clipboard.writeText(password);
        copiedValue.value = password;
    } catch {
        // Clipboard is unavailable (permissions / non-secure context) — the
        // password is still visible in the opened field.
        copiedValue.value = "";
    }
}

watch(
    () => props.form.password,
    (value) => {
        if (!value) generated.value = false;
    },
);

const POLICY_HINT =
    "Минимум 15 символов: заглавные и строчные буквы, цифры и спецсимволы.";
const passwordHint = computed(() => {
    if (generated.value && copied.value) {
        return "Сгенерированный пароль скопирован в буфер обмена.";
    }
    return props.isEdit
        ? `Оставьте пустым, чтобы не менять. ${POLICY_HINT}`
        : POLICY_HINT;
});
</script>

<template>
    <form class="uform" @submit.prevent="emit('submit')">
        <div v-if="isEdit && user" class="uform__profile">
            <NAvatar :name="user.name" :size="52" />
            <div class="uform__profile-meta">
                <div class="uform__profile-name">{{ user.name }}</div>
                <div class="uform__profile-email">{{ user.email }}</div>
            </div>
        </div>

        <NFormField label="Имя" :error="form.errors.name" required>
            <NInput
                v-model="form.name"
                placeholder="Иван Петров"
                :error="!!form.errors.name"
            />
        </NFormField>

        <NFormField label="Email" :error="form.errors.email" required>
            <NInput
                v-model="form.email"
                type="email"
                icon="mail"
                autocomplete="email"
                placeholder="name@example.com"
                :error="!!form.errors.email"
            />
        </NFormField>

        <NFormField
            label="Пароль"
            :error="form.errors.password"
            :hint="passwordHint"
            :required="!isEdit"
        >
            <div class="uform__password">
                <NInput
                    v-model="form.password"
                    :type="generated ? 'text' : 'password'"
                    icon="lock"
                    autocomplete="new-password"
                    reveal-label="Показать пароль"
                    hide-label="Скрыть пароль"
                    :placeholder="
                        isEdit
                            ? 'Оставьте пустым, чтобы не менять'
                            : 'Минимум 15 символов'
                    "
                    :error="!!form.errors.password"
                    class="uform__password-input"
                />
                <NButton
                    variant="secondary"
                    icon="bolt"
                    aria-label="Сгенерировать пароль"
                    @click="onGenerate"
                    >Сгенерировать</NButton
                >
                <NButton
                    v-if="generated"
                    variant="secondary"
                    icon="copy"
                    :aria-label="
                        copied ? 'Пароль скопирован' : 'Скопировать пароль'
                    "
                    @click="copyPassword"
                    >{{ copied ? "Скопировано" : "Копировать" }}</NButton
                >
            </div>
        </NFormField>

        <NFormField
            label="Доступ к панели"
            tag="div"
            label-id="uform-state-label"
        >
            <div
                class="uform__state"
                role="group"
                aria-labelledby="uform-state-label"
            >
                <label class="uform__toggle">
                    <span class="uform__toggle-text">
                        <b>Учётная запись активна</b>
                        <span>{{
                            isSelf
                                ? "Свою учётную запись заблокировать нельзя"
                                : "Заблокированный пользователь не сможет войти"
                        }}</span>
                    </span>
                    <NSwitch
                        v-model="form.is_active"
                        :disabled="isSelf"
                        aria-label="Учётная запись активна"
                    />
                </label>
                <NFormField
                    v-if="!form.is_active"
                    label="Причина блокировки"
                    :error="form.errors.blocked_reason"
                    hint="Пользователь увидит её при попытке входа."
                >
                    <NInput
                        v-model="form.blocked_reason"
                        placeholder="Например: увольнение, подозрительная активность"
                        maxlength="255"
                        :error="!!form.errors.blocked_reason"
                    />
                </NFormField>
                <NCheckbox v-model="form.must_change_password">
                    Потребовать смену пароля при следующем входе
                </NCheckbox>
                <span v-if="form.errors.is_active" class="uform__error">{{
                    form.errors.is_active
                }}</span>
            </div>
        </NFormField>

        <NFormField
            label="Роли"
            :error="form.errors.roles"
            tag="div"
            label-id="uform-roles-label"
        >
            <div
                class="uform__roles"
                role="group"
                aria-labelledby="uform-roles-label"
            >
                <button
                    v-for="r in allRoles"
                    :key="r.name"
                    type="button"
                    class="rolecard"
                    :class="{ 'rolecard--on': isSelected(r.name) }"
                    role="checkbox"
                    :aria-checked="isSelected(r.name)"
                    @click="toggleRole(r.name)"
                >
                    <span
                        class="rolecard__box"
                        :class="{ 'rolecard__box--on': isSelected(r.name) }"
                    >
                        <NIcon class="rolecard__tick" name="check" :size="12" />
                    </span>
                    <span class="rolecard__text">
                        <span class="rolecard__name">{{ r.name }}</span>
                        <span v-if="r.description" class="rolecard__desc">{{
                            r.description
                        }}</span>
                    </span>
                </button>
            </div>
        </NFormField>
    </form>
</template>

<style scoped>
.uform {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.uform__profile {
    display: flex;
    align-items: center;
    gap: 13px;
    padding-bottom: 4px;
}
.uform__profile-meta {
    min-width: 0;
}
.uform__profile-name {
    font-weight: 800;
    font-size: 15.5px;
    letter-spacing: -0.01em;
    color: var(--text);
}
.uform__profile-email {
    font-family: var(--font-mono);
    font-size: 12.5px;
    color: var(--text-3);
    margin-top: 2px;
}

.uform__password {
    display: flex;
    align-items: flex-start;
    gap: 8px;
}
.uform__password-input {
    flex: 1;
    min-width: 0;
}

.uform__state {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.uform__toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    cursor: pointer;
}
.uform__toggle-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 13.5px;
}
.uform__toggle-text span {
    font-size: 12px;
    color: var(--text-3);
}
.uform__error {
    font-size: 12px;
    color: var(--danger);
}

.uform__roles {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.rolecard {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    width: 100%;
    padding: 11px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface);
    text-align: left;
    font-family: inherit;
    cursor: pointer;
    transition:
        border-color 0.14s ease,
        background-color 0.14s ease;
}
.rolecard:hover {
    border-color: var(--border-2);
}
.rolecard--on {
    border-color: var(--accent);
    background: var(--accent-soft);
}
.rolecard:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.rolecard__box {
    flex: none;
    width: 20px;
    height: 20px;
    margin-top: 1px;
    border-radius: 6px;
    border: 1.6px solid var(--border-2);
    background: var(--surface);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition:
        background-color 0.15s,
        border-color 0.15s;
}
.rolecard__box--on {
    border-color: var(--accent);
    background: var(--accent);
}
.rolecard__tick {
    transform: scale(0);
    transition: transform 0.2s cubic-bezier(0.5, 1.6, 0.5, 1);
}
.rolecard__box--on .rolecard__tick {
    transform: scale(1);
}
.rolecard__text {
    min-width: 0;
    line-height: 1.3;
}
.rolecard__name {
    display: block;
    font-weight: 700;
    font-size: 13.5px;
    color: var(--text);
}
.rolecard__desc {
    display: block;
    font-size: 12px;
    color: var(--text-3);
    margin-top: 1px;
}
</style>
