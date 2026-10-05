<script setup lang="ts">
import type { PropType } from "vue";
import { NInput, NTextarea, NCheckbox, NFormField } from "nergous-ui-vue";
import type { PermissionGroups } from "@/admin/types";
import { permissionGroupLabels } from "@/admin/pages/Roles/permissionGroupLabels";

// Presentational form shared by the create/edit role pages. The values are
// v-models of the page's Inertia form; validation errors come in as a map.
const name = defineModel<string>("name", { required: true });
const description = defineModel<string>("description", { required: true });
const permissions = defineModel<string[]>("permissions", { required: true });

defineProps({
    errors: {
        type: Object as PropType<Partial<Record<string, string>>>,
        default: () => ({}),
    },
    allPermissions: {
        type: Object as PropType<PermissionGroups>,
        required: true,
    },
    nameReadonly: { type: Boolean, default: false },
});
const emit = defineEmits(["submit"]);

function toggle(permission: string, checked: boolean) {
    const set = new Set(permissions.value);
    if (checked) set.add(permission);
    else set.delete(permission);
    permissions.value = Array.from(set);
}

type Perm = { name: string };

function groupState(perms: Perm[]): "all" | "some" | "none" {
    const picked = perms.filter((p) =>
        permissions.value.includes(p.name),
    ).length;
    if (picked === 0) return "none";
    return picked === perms.length ? "all" : "some";
}

// Clicking a group title selects the whole group, or clears it when every item is already on.
function toggleGroup(perms: Perm[]) {
    const set = new Set<string>(permissions.value);
    const select = groupState(perms) !== "all";
    for (const p of perms) {
        if (select) set.add(p.name);
        else set.delete(p.name);
    }
    permissions.value = Array.from(set);
}
</script>

<template>
    <form class="rform" @submit.prevent="emit('submit')">
        <NFormField label="Название роли" :error="errors.name" required>
            <NInput
                v-model="name"
                :readonly="nameReadonly"
                placeholder="например, editor"
                :error="!!errors.name"
            />
        </NFormField>

        <NFormField
            label="Описание"
            :error="errors.description"
            hint="Короткое пояснение, что разрешает роль"
        >
            <NTextarea
                v-model="description"
                :rows="2"
                placeholder="например, создание и редактирование контента"
                :error="!!errors.description"
            />
        </NFormField>

        <NFormField
            tag="div"
            label="Разрешения"
            label-id="rform-perms-label"
            :error="errors.permissions"
        >
            <div
                class="rform__matrix"
                role="group"
                aria-labelledby="rform-perms-label"
            >
                <div
                    v-for="(perms, group) in allPermissions"
                    :key="group"
                    class="rform__group"
                    role="group"
                    :aria-labelledby="`rform-group-${group}`"
                >
                    <NCheckbox
                        :id="`rform-group-${group}`"
                        class="rform__group-title"
                        :model-value="groupState(perms) === 'all'"
                        :indeterminate="groupState(perms) === 'some'"
                        @update:model-value="toggleGroup(perms)"
                        >{{ permissionGroupLabels[group] ?? group }}</NCheckbox
                    >
                    <div class="rform__perms">
                        <NCheckbox
                            v-for="p in perms"
                            :key="p.name"
                            :model-value="permissions.includes(p.name)"
                            @update:model-value="(v) => toggle(p.name, v)"
                            >{{ p.name }}</NCheckbox
                        >
                    </div>
                </div>
            </div>
        </NFormField>
    </form>
</template>

<style scoped>
.rform {
    display: flex;
    flex-direction: column;
    gap: 18px;
}
.rform__matrix {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
}
.rform__group {
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 14px;
    background: var(--surface-2);
}
/* Parent selector outweighs NCheckbox's own label styles. */
.rform__group .rform__group-title {
    display: flex;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    color: var(--text-3);
    margin-bottom: 10px;
}
.rform__perms {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
</style>
