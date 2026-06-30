<script setup>
import { useForm } from "@inertiajs/vue3";
import AdminLayout from "@/admin/layouts/AdminLayout.vue";
import { NCard } from "@/lib/nergous-cit";
import RoleForm from "@/admin/pages/Roles/Partials/Form.vue";

const props = defineProps({
    allPermissions: { type: Object, required: true },
    assigned: { type: Array, default: () => [] },
});
const form = useForm({
    name: "",
    description: "",
    permissions: [...props.assigned],
});
function submit() {
    form.post("/admin/roles");
}
</script>

<template>
    <AdminLayout title="Новая роль">
        <div class="page">
            <NCard>
                <RoleForm
                    :form="form"
                    :all-permissions="allPermissions"
                    submit-label="Создать"
                    @submit="submit"
                />
            </NCard>
        </div>
    </AdminLayout>
</template>
