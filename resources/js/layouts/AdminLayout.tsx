import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Link, router, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Building2,
    ChevronDown,
    GraduationCap,
    LayoutDashboard,
    LogOut,
    Menu,
    MessageSquareText,
    PlugZap,
    School,
    Settings,
    Shield,
    Smartphone,
    Target,
    User,
    UserCheck,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';

interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: string;
    school?: {
        id: number;
        name: string;
    };
}

interface AdminLayoutProps {
    children: React.ReactNode;
    admin: AdminUser;
}

export default function AdminLayout({ children, admin }: AdminLayoutProps) {
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [expandedMenus, setExpandedMenus] = useState<string[]>([
        'Academic Structure',
    ]); // Default expanded
    const { url } = usePage();

    const isSuper = admin.role === 'super_admin';

    const toggleMenu = (menuName: string) => {
        setExpandedMenus((prev) =>
            prev.includes(menuName)
                ? prev.filter((name) => name !== menuName)
                : [...prev, menuName],
        );
    };

    const NavigationItem = ({
        item,
        inMobile = false,
    }: {
        item: any;
        inMobile?: boolean;
    }) => {
        const Icon = item.icon;
        const hasChildren = item.children && item.children.length > 0;
        const isExpanded = expandedMenus.includes(item.name);

        if (hasChildren) {
            return (
                <div key={item.name}>
                    <button
                        onClick={() => toggleMenu(item.name)}
                        className={`group flex w-full items-center rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                            item.current
                                ? 'bg-blue-50 text-blue-700'
                                : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900'
                        }`}
                    >
                        <Icon
                            className={`mr-3 h-5 w-5 ${
                                item.current
                                    ? 'text-blue-600'
                                    : 'text-gray-400 group-hover:text-gray-600'
                            }`}
                        />
                        {item.name}
                        <ChevronDown
                            className={`ml-auto h-4 w-4 transition-transform ${
                                isExpanded ? 'rotate-180' : ''
                            }`}
                        />
                    </button>
                    {isExpanded && (
                        <div className="mt-1 ml-6 space-y-1">
                            {item.children.map((child: any) => (
                                <NavigationItem
                                    key={child.name}
                                    item={child}
                                    inMobile={inMobile}
                                />
                            ))}
                        </div>
                    )}
                </div>
            );
        }

        return (
            <Link
                key={item.name}
                href={item.href}
                className={`group flex items-center rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                    item.current
                        ? 'border-r-2 border-blue-600 bg-blue-50 text-blue-700'
                        : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900'
                }`}
                onClick={inMobile ? () => setSidebarOpen(false) : undefined}
            >
                <Icon
                    className={`mr-3 h-5 w-5 ${
                        item.current
                            ? 'text-blue-600'
                            : 'text-gray-400 group-hover:text-gray-600'
                    }`}
                />
                {item.name}
            </Link>
        );
    };

    const navigation = [
        {
            name: 'Dashboard',
            href: '/admin/dashboard',
            icon: LayoutDashboard,
            current: url === '/admin/dashboard',
        },
        {
            name: 'Students',
            href: '/admin/students',
            icon: Users,
            current: url.startsWith('/admin/students'),
        },
        {
            name: 'Academic Structure',
            icon: BookOpen,
            current:
                url.startsWith('/admin/sections') ||
                url.startsWith('/admin/options') ||
                url.startsWith('/admin/levels') ||
                url.startsWith('/admin/classes'),
            children: [
                {
                    name: 'Sections',
                    href: '/admin/sections',
                    icon: BookOpen,
                    current: url.startsWith('/admin/sections'),
                },
                {
                    name: 'Options',
                    href: '/admin/options',
                    icon: GraduationCap,
                    current: url.startsWith('/admin/options'),
                },
                {
                    name: 'Levels',
                    href: '/admin/levels',
                    icon: Target,
                    current: url.startsWith('/admin/levels'),
                },
                {
                    name: 'Classes',
                    href: '/admin/classes',
                    icon: Building2,
                    current: url.startsWith('/admin/classes'),
                },
            ],
        },
        {
            name: 'Attendance',
            href: '/admin/attendance',
            icon: UserCheck,
            current: url.startsWith('/admin/attendance'),
        },
        {
            name: 'Conversations',
            href: '/admin/conversations',
            icon: MessageSquareText,
            current: url.startsWith('/admin/conversations'),
        },
        {
            name: 'Devices',
            href: '/admin/devices',
            icon: Smartphone,
            current: url.startsWith('/admin/devices'),
        },
        {
            name: 'Integrations',
            href: '/admin/integrations',
            icon: PlugZap,
            current: url.startsWith('/admin/integrations'),
        },
    ];

    // Add super admin only navigation items
    if (isSuper) {
        navigation.splice(1, 0, {
            name: 'Schools',
            href: '/admin/schools',
            icon: School,
            current: url.startsWith('/admin/schools'),
        });
        navigation.push({
            name: 'Admin Users',
            href: '/admin/admin-users',
            icon: Shield,
            current: url.startsWith('/admin/admin-users'),
        });
    }

    navigation.push({
        name: 'Settings',
        href: '/admin/settings',
        icon: Settings,
        current: url.startsWith('/admin/settings'),
    });

    const handleLogout = () => {
        router.post('/admin/logout');
    };

    return (
        <div className="min-h-screen bg-gray-50">
            {/* Mobile sidebar */}
            <div
                className={`fixed inset-0 z-50 lg:hidden ${sidebarOpen ? 'block' : 'hidden'}`}
            >
                <div
                    className="bg-opacity-75 fixed inset-0 bg-gray-600"
                    onClick={() => setSidebarOpen(false)}
                />
                <div className="fixed inset-y-0 left-0 flex w-64 flex-col bg-white shadow-xl">
                    <div className="flex h-16 items-center justify-between border-b px-6">
                        <div className="flex items-center">
                            <Shield className="h-8 w-8 text-blue-600" />
                            <span className="ml-2 text-xl font-bold text-gray-900">
                                Admin
                            </span>
                        </div>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setSidebarOpen(false)}
                        >
                            <X className="h-5 w-5" />
                        </Button>
                    </div>
                    <nav className="flex-1 space-y-1 px-4 py-6">
                        {navigation.map((item) => (
                            <NavigationItem
                                key={item.name}
                                item={item}
                                inMobile={true}
                            />
                        ))}
                    </nav>
                </div>
            </div>

            {/* Desktop sidebar */}
            <div className="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-64 lg:flex-col">
                <div className="flex flex-grow flex-col border-r border-gray-200 bg-white">
                    {/* Logo */}
                    <div className="flex h-16 items-center border-b px-6">
                        <Shield className="h-8 w-8 text-blue-600" />
                        <span className="ml-2 text-xl font-bold text-gray-900">
                            Admin Panel
                        </span>
                    </div>

                    {/* Admin Info */}
                    <div className="border-b bg-gray-50 px-6 py-4">
                        <div className="flex items-center">
                            <Avatar className="h-10 w-10">
                                <AvatarImage
                                    src={`https://api.dicebear.com/7.x/initials/svg?seed=${admin.name}`}
                                />
                                <AvatarFallback>
                                    {admin.name.charAt(0)}
                                </AvatarFallback>
                            </Avatar>
                            <div className="ml-3 flex-1">
                                <p className="text-sm font-medium text-gray-900">
                                    {admin.name}
                                </p>
                                <div className="mt-1 flex items-center">
                                    <Badge
                                        variant={
                                            isSuper ? 'default' : 'secondary'
                                        }
                                        className="text-xs"
                                    >
                                        {isSuper
                                            ? 'Super Admin'
                                            : 'School Admin'}
                                    </Badge>
                                </div>
                                {!isSuper && admin.school && (
                                    <p className="mt-1 text-xs text-gray-600">
                                        {admin.school.name}
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Navigation */}
                    <nav className="flex-1 space-y-1 px-4 py-6">
                        {navigation.map((item) => (
                            <NavigationItem key={item.name} item={item} />
                        ))}
                    </nav>
                </div>
            </div>

            {/* Main content */}
            <div className="lg:pl-64">
                {/* Top navigation */}
                <div className="sticky top-0 z-40 flex h-16 items-center gap-x-4 border-b border-gray-200 bg-white px-4 shadow-sm sm:gap-x-6 sm:px-6 lg:px-8">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="lg:hidden"
                        onClick={() => setSidebarOpen(true)}
                    >
                        <Menu className="h-5 w-5" />
                    </Button>

                    <div className="flex flex-1 gap-x-4 self-stretch lg:gap-x-6">
                        <div className="flex flex-1" />

                        {/* User menu */}
                        <div className="flex items-center gap-x-4 lg:gap-x-6">
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        className="flex items-center gap-x-2"
                                    >
                                        <Avatar className="h-8 w-8">
                                            <AvatarImage
                                                src={`https://api.dicebear.com/7.x/initials/svg?seed=${admin.name}`}
                                            />
                                            <AvatarFallback>
                                                {admin.name.charAt(0)}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="hidden lg:flex lg:flex-col lg:items-start">
                                            <span className="text-sm font-semibold">
                                                {admin.name}
                                            </span>
                                            <span className="text-xs text-gray-600">
                                                {admin.email}
                                            </span>
                                        </div>
                                        <ChevronDown className="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    className="w-56"
                                >
                                    <DropdownMenuLabel>
                                        <div className="flex flex-col space-y-1">
                                            <p className="text-sm font-medium">
                                                {admin.name}
                                            </p>
                                            <p className="text-xs text-gray-600">
                                                {admin.email}
                                            </p>
                                        </div>
                                    </DropdownMenuLabel>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem asChild>
                                        <Link
                                            href="/admin/settings"
                                            className="flex items-center"
                                        >
                                            <User className="mr-2 h-4 w-4" />
                                            Profile Settings
                                        </Link>
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        className="text-red-600 focus:text-red-600"
                                        onClick={handleLogout}
                                    >
                                        <LogOut className="mr-2 h-4 w-4" />
                                        Logout
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                </div>

                {/* Page content */}
                <main className="px-4 py-6 sm:px-6 lg:px-8">{children}</main>
            </div>
        </div>
    );
}
