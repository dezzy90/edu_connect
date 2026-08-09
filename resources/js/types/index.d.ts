import { InertiaLinkProps } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    sidebarOpen: boolean;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

// Academic Structure Types
export interface Admin {
    id: number;
    name: string;
    email: string;
    role: 'super_admin' | 'school_admin';
    school_id?: number;
    school?: School;
    created_at: string;
    updated_at: string;
}

export interface School {
    id: number;
    name: string;
    address?: string;
    phone?: string;
    email?: string;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

export interface Section {
    id: number;
    name: string;
    code: string;
    description?: string;
    school_id: number;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

export interface Option {
    id: number;
    name: string;
    code: string;
    type?: string;
    description?: string;
    section_id: number;
    school_id: number;
    is_active: boolean;
    created_at: string;
    updated_at: string;
    // Relationships (optional when not loaded)
    section?: Section & {
        school?: School;
    };
    school?: School;
    // Counts (optional when not loaded)
    levels_count?: number;
    classes_count?: number;
    students_count?: number;
}

export interface Level {
    id: number;
    name: string;
    code: string;
    description?: string;
    option_id: number;
    school_id: number;
    order: number;
    is_active: boolean;
    created_at: string;
    updated_at: string;
    // Relationships (optional when not loaded)
    option?: Option & {
        section?: Section;
    };
    // Counts (optional when not loaded)
    classes_count?: number;
}

export interface SchoolClass {
    id: number;
    name: string;
    code: string;
    level_id: number;
    school_id: number;
    capacity: number;
    academic_year: string;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}
