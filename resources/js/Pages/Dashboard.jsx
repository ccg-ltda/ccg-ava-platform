import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function Dashboard({ stats }) {
    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />
            <div className="mx-auto max-w-7xl">
                <div className="mb-8">
                    <h1 className="font-orbitron text-2xl sm:text-3xl font-extrabold text-white tracking-wider drop-shadow-[0_0_15px_rgba(0,240,255,0.5)]">
                        PANEL DE CONTROL
                    </h1>
                    <p className="mt-1 font-orbitron text-xs tracking-widest text-cyan-400/80 uppercase">
                        Sistema Central de Operaciones - CCG
                    </p>
                </div>
                
                <div className="grid gap-6 md:grid-cols-3">
                    {/* Stat Card 1 */}
                    <div className="glass-panel relative rounded-2xl p-6 transition-all duration-300 hover:border-cyan-400 hover:shadow-[0_0_30px_rgba(0,240,255,0.3)] group">
                        <div className="absolute top-0 left-0 w-3 h-3 border-t-2 border-l-2 border-cyan-400 rounded-tl-lg"></div>
                        <div className="absolute top-0 right-0 w-3 h-3 border-t-2 border-r-2 border-cyan-400 rounded-tr-lg"></div>
                        <div className="absolute bottom-0 left-0 w-3 h-3 border-b-2 border-l-2 border-cyan-400 rounded-bl-lg"></div>
                        <div className="absolute bottom-0 right-0 w-3 h-3 border-b-2 border-r-2 border-cyan-400 rounded-br-lg"></div>

                        <h3 className="font-orbitron text-xs tracking-widest text-slate-400 uppercase mb-2">Total Usuarios</h3>
                        <p className="font-orbitron text-4xl font-black text-cyan-300 drop-shadow-[0_0_15px_rgba(0,240,255,0.6)]">
                            {stats?.users ?? 0}
                        </p>
                        <div className="mt-4 flex items-center gap-1.5 text-[10px] font-orbitron text-emerald-400">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                            Activo en red
                        </div>
                    </div>

                    {/* Stat Card 2 */}
                    <div className="glass-panel relative rounded-2xl p-6 transition-all duration-300 hover:border-cyan-400 hover:shadow-[0_0_30px_rgba(0,240,255,0.3)] group">
                        <div className="absolute top-0 left-0 w-3 h-3 border-t-2 border-l-2 border-cyan-400 rounded-tl-lg"></div>
                        <div className="absolute top-0 right-0 w-3 h-3 border-t-2 border-r-2 border-cyan-400 rounded-tr-lg"></div>
                        <div className="absolute bottom-0 left-0 w-3 h-3 border-b-2 border-l-2 border-cyan-400 rounded-bl-lg"></div>
                        <div className="absolute bottom-0 right-0 w-3 h-3 border-b-2 border-r-2 border-cyan-400 rounded-br-lg"></div>

                        <h3 className="font-orbitron text-xs tracking-widest text-slate-400 uppercase mb-2">Roles Activos</h3>
                        <p className="font-orbitron text-4xl font-black text-blue-400 drop-shadow-[0_0_15px_rgba(0,136,255,0.6)]">
                            {stats?.roles ?? 0}
                        </p>
                        <div className="mt-4 flex items-center gap-1.5 text-[10px] font-orbitron text-emerald-400">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                            Sincronizado
                        </div>
                    </div>

                    {/* Stat Card 3 */}
                    <div className="glass-panel relative rounded-2xl p-6 transition-all duration-300 hover:border-cyan-400 hover:shadow-[0_0_30px_rgba(0,240,255,0.3)] group">
                        <div className="absolute top-0 left-0 w-3 h-3 border-t-2 border-l-2 border-cyan-400 rounded-tl-lg"></div>
                        <div className="absolute top-0 right-0 w-3 h-3 border-t-2 border-r-2 border-cyan-400 rounded-tr-lg"></div>
                        <div className="absolute bottom-0 left-0 w-3 h-3 border-b-2 border-l-2 border-cyan-400 rounded-bl-lg"></div>
                        <div className="absolute bottom-0 right-0 w-3 h-3 border-b-2 border-r-2 border-cyan-400 rounded-br-lg"></div>

                        <h3 className="font-orbitron text-xs tracking-widest text-slate-400 uppercase mb-2">Permisos</h3>
                        <p className="font-orbitron text-4xl font-black text-cyan-200 drop-shadow-[0_0_15px_rgba(0,240,255,0.6)]">
                            {stats?.permissions ?? 0}
                        </p>
                        <div className="mt-4 flex items-center gap-1.5 text-[10px] font-orbitron text-emerald-400">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                            Verificado
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
