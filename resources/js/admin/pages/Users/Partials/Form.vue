<script setup>
import { NInput, NAvatar, NIcon, NFormField } from "@/lib/nergous-cit";
import { formatDateShort, formatDateTime } from "@/lib/format.js";

const props = defineProps({
    // useForm({ name, email, password, roles:[names] })
    form: { type: Object, required: true },
    // [{ name, description }] — полный список назначаемых ролей.
    allRoles: { type: Array, required: true },
    isEdit: { type: Boolean, default: false },
    // На edit показываем шапку профиля + панель «Сведения».
    user: { type: Object, default: null },
});

function toggleRole(name) {
    const set = new Set(props.form.roles);
    set.has(name) ? set.delete(name) : set.add(name);
    props.form.roles = Array.from(set);
}
function isSelected(name) {
    return props.form.roles.includes(name);
}
</script>

<template>
    <form class="uform" @submit.prevent>
        <!-- Edit: шапка профиля -->
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
                placeholder="name@nergous-cit.app"
                :error="!!form.errors.email"
            />
        </NFormField>

        <NFormField
            label="Пароль"
            :error="form.errors.password"
            :hint="
                isEdit
                    ? 'Оставьте пустым, чтобы не менять.'
                    : 'Минимум 8 символов.'
            "
            :required="!isEdit"
        >
            <NInput
                v-model="form.password"
                type="password"
                icon="lock"
                autocomplete="new-password"
                :placeholder="
                    isEdit
                        ? 'Оставьте пустым, чтобы не менять'
                        : 'Минимум 8 символов'
                "
                :error="!!form.errors.password"
            />
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

        <!-- Edit: панель «Сведения» -->
        <div v-if="isEdit && user" class="uform__panel">
            <h4 class="uform__panel-title">Сведения</h4>
            <div class="uform__panel-row">
                <span class="uform__panel-key">Добавлен</span>
                <span class="uform__panel-val">{{
                    formatDateShort(user.created_at)
                }}</span>
            </div>
            <div class="uform__panel-row">
                <span class="uform__panel-key">Обновлено</span>
                <span class="uform__panel-val">{{
                    formatDateTime(user.updated_at)
                }}</span>
            </div>
            <div class="uform__panel-row">
                <span class="uform__panel-key">Создал</span>
                <span class="uform__panel-val">{{
                    user.creator?.name ?? "—"
                }}</span>
            </div>
            <div class="uform__panel-row">
                <span class="uform__panel-key">Изменил</span>
                <span class="uform__panel-val">{{
                    user.editor?.name ?? "—"
                }}</span>
            </div>
            <div class="uform__panel-row">
                <span class="uform__panel-key">Идентификатор</span>
                <span class="uform__panel-val uform__panel-val--mono"
                    >#{{ user.id }}</span
                >
            </div>
        </div>
    </form>
</template>

<style scoped>
.uform {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* --- профиль (edit) --- */
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

/* --- роли как карточки-опции --- */
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

/* --- панель «Сведения» (edit) --- */
.uform__panel {
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface-2);
}
.uform__panel-title {
    margin: 0 0 10px;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: var(--text-3);
}
.uform__panel-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 13px;
    padding: 4px 0;
}
.uform__panel-key {
    color: var(--text-3);
}
.uform__panel-val {
    font-weight: 600;
    color: var(--text);
}
.uform__panel-val--mono {
    font-family: var(--font-mono);
}
</style>
