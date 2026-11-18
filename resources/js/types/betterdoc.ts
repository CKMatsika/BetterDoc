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
    active_profile_code: 'defence' | 'financial' | 'government' | 'corporate';
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

export interface UserNotificationItem {
    id: string;
    type: string;
    title: string;
    body: string;
    document_id: string | null;
    action_url: string | null;
    read_at: string | null;
    created_at: string | null;
}

export interface DocumentAnnotationItem {
    id: string;
    version_id: string;
    page_number: number;
    kind: 'note' | 'highlight' | 'redaction_mark';
    coords: {
        x: number;
        y: number;
        width: number;
        height: number;
    };
    content: string | null;
    redacted_in_version_id: string | null;
    creator_name: string | null;
}

export interface DocumentCommentItem {
    id: string;
    body: string;
    author_name: string;
    resolved_at: string | null;
    suppressed_mentions_count: number;
    created_at: string | null;
    replies: {
        id: string;
        body: string;
        author_name: string;
        created_at: string | null;
    }[];
}

export interface BrandingPayload {
    id: string | null;
    profile_key: string;
    name: string;
    short_name: string;
    tagline: string;
    logo_url: string | null;
    logo_dark_url: string | null;
    favicon_url: string;
    primary_color: string;
    primary_fg: string;
    accent_color: string;
    accent_fg: string;
    welcome_title: string;
    welcome_text: string;
    notice_text: string;
    notice_enabled: boolean;
    support_email: string;
    support_phone: string;
    support_hours: string;
    address: string;
    website: string;
    show_powered_by: boolean;
    landing_mode_setting: string | null;
    effective_landing_mode: 'portal' | 'demo' | 'off';
    contrast: {
        primary_fg: string;
        primary_button_ratio: number;
        primary_on_light_ratio: number;
        accent_fg: string;
        accent_button_ratio: number;
        accent_on_light_ratio: number;
        warnings: string[];
    };
}

export interface SharedProps {
    app: {
        name: string;
        air_gapped: boolean;
        demo_switcher_enabled: boolean;
        self_registration_enabled: boolean;
        reverb?: {
            enabled: boolean;
            key: string;
            host: string;
            port: number;
            scheme: string;
        };
    };
    auth: {
        user: AuthUser;
    } | null;
    tenant: TenantInfo | null;
    branding: BrandingPayload;
    pendingApprovalsCount: number;
    pendingWorkflowTasksCount: number;
    unreadNotificationsCount: number;
    unreadNotifications: UserNotificationItem[];
    flash: {
        success: string | null;
        error: string | null;
    };
}
