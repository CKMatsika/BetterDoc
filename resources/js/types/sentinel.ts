export interface ClearanceSummary {
    id: string;
    code: string;
    name: string;
    color_hex: string;
    watermark_mode?: string;
    two_person_required?: boolean;
}

export interface CompartmentSummary {
    id: string;
    code: string;
    name: string;
}

export interface AuthUser {
    id: string;
    name: string;
    email: string;
    service_number: string | null;
    rank_title: string | null;
    clearance_rank: number;
    clearance: ClearanceSummary | null;
    org_unit: {
        id: string;
        code: string;
        name: string;
        path: string;
    } | null;
    compartments: CompartmentSummary[];
    roles: string[];
    mfa_configured: boolean;
    mfa_step_up_fresh: boolean;
}

export interface TenantInfo {
    id: string;
    slug: string;
    name: string;
    hosting_mode: string;
    hosting_mode_label: string;
    air_gapped: boolean;
    active_profile_code: 'defence' | 'financial' | 'corporate';
    branding: {
        organization_name?: string;
        short_name?: string;
        primary_color?: string;
        accent_color?: string;
        banner_text_prefix?: string;
    };
    terminology: {
        cabinet?: string;
        folder?: string;
        document?: string;
        unit?: string;
        registry_item?: string;
        approver?: string;
    };
    features: Record<string, boolean>;
}

export interface SharedProps {
    app: {
        name: string;
        air_gapped: boolean;
        demo_switcher_enabled: boolean;
    };
    auth: {
        user: AuthUser;
    } | null;
    tenant: TenantInfo | null;
    pendingApprovalsCount: number;
    flash: {
        success: string | null;
        error: string | null;
    };
}
