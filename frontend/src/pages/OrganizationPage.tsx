import { useQuery } from '@tanstack/react-query';
import { Building2, School } from 'lucide-react';
import EmptyState from '../components/EmptyState';
import LoadingState from '../components/LoadingState';
import StatusBadge from '../components/StatusBadge';
import { adminApi } from '../lib/api';

export default function OrganizationPage() {
  const dashboardQuery = useQuery({
    queryKey: ['dashboard'],
    queryFn: adminApi.dashboard,
  });

  if (dashboardQuery.isLoading) {
    return <LoadingState label="Loading organization" />;
  }

  const tenants = dashboardQuery.data?.organization.tenants ?? [];
  const schools = dashboardQuery.data?.organization.schools ?? [];

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Data visibility</p>
          <h1>Organization</h1>
          <p>Schools shown here are the v2 Edu-connect records visible to the current administrator.</p>
        </div>
      </section>

      <section className="two-column align-start">
        <div className="panel">
          <div className="panel-header">
            <div>
              <h2>Tenants</h2>
              <p>Top-level institutions synced or created in Edu-connect.</p>
            </div>
          </div>
          <div className="list-stack">
            {tenants.length === 0 ? (
              <EmptyState icon={Building2} title="No tenants visible" message="Sync from Edu-admin or create local tenant data first." />
            ) : (
              tenants.map((tenant) => (
                <div className="list-row" key={tenant.id}>
                  <div>
                    <strong>{tenant.name}</strong>
                    <span>{tenant.slug}</span>
                  </div>
                  <div className="row-meta">
                    <StatusBadge status={tenant.status} />
                    <small>{tenant.schools_count} schools</small>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>

        <div className="panel">
          <div className="panel-header">
            <div>
              <h2>Schools</h2>
              <p>Enrollment creates the channels and class group memberships used by parents.</p>
            </div>
          </div>
          <div className="table-wrap">
            {schools.length === 0 ? (
              <EmptyState icon={School} title="No schools visible" message="A school admin must be mapped to a v2 school source record." />
            ) : (
              <table>
                <thead>
                  <tr>
                    <th>School</th>
                    <th>Students</th>
                    <th>Classes</th>
                    <th>Devices</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {schools.map((school) => (
                    <tr key={school.id}>
                      <td>
                        <strong>{school.name}</strong>
                        <span>{school.code ?? school.slug}</span>
                      </td>
                      <td>{school.students_count}</td>
                      <td>{school.classes_count}</td>
                      <td>{school.devices_count}</td>
                      <td><StatusBadge status={school.status} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </div>
      </section>
    </div>
  );
}
