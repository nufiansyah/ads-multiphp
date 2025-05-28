import Link from 'next/link';
import React from 'react';
import { useRouter } from 'next/router';

const NavBar: React.FC = () => {
  const router = useRouter();
  const { pathname } = router;

  const navItems = [
    { href: '/', label: 'Dashboard' },
    { href: '/publishers', label: 'Publishers' },
    { href: '/demand-sources', label: 'Demand Sources' },
    { href: '/analytics', label: 'Analytics' },
  ];

  return (
    <nav className="bg-blue-600 p-4 flex space-x-4">
      {navItems.map(item => {
        const isActive = pathname === item.href;
        return (
          <Link
            key={item.href}
            href={item.href}
            className={`text-white px-3 py-2 rounded ${
              isActive ? 'bg-blue-800' : 'hover:bg-blue-500'
            }`}
          >
            {item.label}
          </Link>
        );
      })}
    </nav>
  );
};

export default NavBar;